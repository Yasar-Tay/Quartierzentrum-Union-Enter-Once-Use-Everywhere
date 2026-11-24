<?php

declare(strict_types=1);

namespace EnterOnce\Core;

/**
 * Step 5 of the core: compares target records with the site's managed records and
 * emits create / update / unpublish / keep / hold (R-04 to R-08, R-13, R-23, R-27, R-31).
 */
final class Differ
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @param list<SiteRecord> $siteRecords all records the shell found; foreign and unmanaged ones are ignored (R-04)
     * @param bool $releaseHeld true after «Trotzdem anwenden»: R-13 does not hold this run
     */
    public function diff(TargetSet $targets, array $siteRecords, bool $releaseHeld = false): SyncPlan
    {
        $now = $targets->now;
        $window = $targets->window;
        $source = $this->settings->sourceId;

        // R-04: only managed records of this source.
        $site = [];
        foreach ($siteRecords as $record) {
            if ($record->managed && $record->source === $source) {
                $site[$record->key] = $record;
            }
        }
        $matched = [];
        $actions = [];

        $pendingKeys = [];
        foreach ($targets->pending as $p) {
            $pendingKeys[$p->key] = true;
        }

        // Pass 1: pair targets with records by key.
        /** @var array<string, TargetRecord> $pairs site key => target */
        $pairs = [];
        $new = [];
        foreach ($targets->targets as $target) {
            if (isset($site[$target->key])) {
                $pairs[$target->key] = $target;
            } else {
                $new[] = $target;
            }
        }
        // Pass 2: new targets; R-27 may take over a draft whose own target is over (ended or cancelled).
        $reused = [];
        foreach ($new as $target) {
            $action = $this->forNewTarget($target, $site, $pairs, $reused);
            if ($action !== null) {
                $actions[] = $action;
            }
        }
        foreach ($reused as $key => $_) {
            unset($pairs[$key]);
            $matched[$key] = true;
        }
        foreach ($pairs as $key => $target) {
            $matched[$key] = true;
            $actions[] = $this->forExisting($target, $site[$key]);
        }

        // Records the calendar no longer has.
        foreach ($site as $key => $record) {
            if (isset($matched[$key])) {
                continue;
            }
            if (isset($pendingKeys[$key])) {
                // A weekly offer that exists but is not due yet (its first date moved later): leave it.
                $actions[] = new Action(Action::KEEP, $record->kind, $key, 'not_yet_due', $record->status, 0, null, $key, $record->id);
                continue;
            }
            $actions[] = $this->forMissing($record, $now, $window);
        }

        // R-13: more than bulk_unpublish_limit unpublishes in one run → none of them.
        $held = false;
        $unpublishes = array_filter($actions, static fn (Action $a) => $a->type === Action::UNPUBLISH);
        if (!$releaseHeld && count($unpublishes) > $this->settings->bulkUnpublishLimit) {
            $held = true;
            $actions = array_map(static function (Action $a): Action {
                if ($a->type !== Action::UNPUBLISH) {
                    return $a;
                }

                return new Action(Action::HOLD, $a->kind, $a->key, $a->reason, TargetRecord::PUBLISH, $a->missingRuns, $a->target, $a->siteKey, $a->siteId, null, $a->flagChanges);
            }, $actions);
        }

        return new SyncPlan($source, array_values($actions), $this->settings->isDryRun(), $held);
    }

    /**
     * @param array<string, SiteRecord> $site
     * @param array<string, TargetRecord> $pairs
     * @param array<string, true> $reused
     */
    private function forNewTarget(TargetRecord $target, array $site, array $pairs, array &$reused): ?Action
    {
        if ($target->status !== TargetRecord::PUBLISH || $target->past) {
            // Nothing to create: cancelled, ended or past (R-07).
            return null;
        }
        if ($target->kind === TargetRecord::WEEKLY_OFFER) {
            $draft = $this->reusableDraft($target, $site, $pairs, $reused);
            if ($draft !== null) {
                $reused[$draft->key] = true;

                return new Action(
                    Action::UPDATE,
                    $target->kind,
                    $target->key,
                    'reuse_draft',
                    TargetRecord::PUBLISH,
                    0,
                    $target,
                    $draft->key,
                    $draft->id,
                    $target->hashFor($draft->keepText),
                    $this->flagChanges($target, $draft),
                );
            }
        }

        // A created record carries the calendar's text, so it never has keep_text.
        return new Action(Action::CREATE, $target->kind, $target->key, 'new', TargetRecord::PUBLISH, 0, $target, null, null, $target->hash);
    }

    private function forExisting(TargetRecord $target, SiteRecord $record): Action
    {
        $hash = $target->hashFor($record->keepText);
        $flags = $this->flagChanges($target, $record);

        if ($target->past) {
            // R-07: past records are never changed. R-23: an ended weekly offer is set to draft once.
            if ($target->kind === TargetRecord::WEEKLY_OFFER && $record->isPublished()) {
                return new Action(Action::UNPUBLISH, $target->kind, $target->key, 'series_ended', TargetRecord::DRAFT, 0, $target, $record->key, $record->id);
            }

            return new Action(Action::KEEP, $target->kind, $target->key, 'past', $record->status, 0, $target, $record->key, $record->id);
        }
        if ($target->status === TargetRecord::DRAFT) {
            if ($record->isPublished()) {
                // A-06: a cancelled event is unpublished at once, without grace runs.
                return new Action(Action::UNPUBLISH, $target->kind, $target->key, (string) $target->statusReason, TargetRecord::DRAFT, 0, $target, $record->key, $record->id);
            }

            return new Action(Action::KEEP, $target->kind, $target->key, 'already_draft', $record->status, 0, $target, $record->key, $record->id);
        }
        if (!$record->isPublished()) {
            // R-06: an event that returns publishes the same record again.
            return new Action(Action::UPDATE, $target->kind, $target->key, 'republish', TargetRecord::PUBLISH, 0, $target, $record->key, $record->id, $hash, $flags);
        }
        if ($record->hash !== $hash) {
            return new Action(Action::UPDATE, $target->kind, $target->key, 'changed', TargetRecord::PUBLISH, 0, $target, $record->key, $record->id, $hash, $flags);
        }

        return new Action(Action::KEEP, $target->kind, $target->key, 'unchanged', $record->status, 0, $target, $record->key, $record->id);
    }

    private function forMissing(SiteRecord $record, \DateTimeImmutable $now, Window $window): Action
    {
        $keep = fn (string $reason, int $missing) => new Action(Action::KEEP, $record->kind, $record->key, $reason, $record->status, $missing, null, $record->key, $record->id);

        if ($record->end !== null && $record->end <= $now) {
            // R-07, with the one exception of R-23 for weekly offers.
            if ($record->kind === TargetRecord::WEEKLY_OFFER && $record->isPublished()) {
                return new Action(Action::UNPUBLISH, $record->kind, $record->key, 'series_ended', TargetRecord::DRAFT, $record->missingRuns, null, $record->key, $record->id);
            }

            return $keep('past', $record->missingRuns);
        }
        // R-08: only inside the window.
        if (($record->start !== null && $record->start >= $window->end) || ($record->end !== null && $record->end <= $window->start)) {
            return $keep('outside_window', $record->missingRuns);
        }
        if (!$record->isPublished()) {
            return $keep('already_draft', $record->missingRuns);
        }
        // R-05: unpublish after removal_grace_runs consecutive successful runs without the record.
        $missing = $record->missingRuns + 1;
        if ($missing >= $this->settings->removalGraceRuns) {
            return new Action(Action::UNPUBLISH, $record->kind, $record->key, 'missing', TargetRecord::DRAFT, $missing, null, $record->key, $record->id);
        }

        return $keep('missing_grace', $missing);
    }

    /**
     * R-27: a managed draft weekly offer of this source with the same weekday, start time and
     * normalised title, whose own series is gone from the file or is over (ended or cancelled).
     *
     * @param array<string, SiteRecord> $site
     * @param array<string, TargetRecord> $pairs
     * @param array<string, true> $reused
     */
    private function reusableDraft(TargetRecord $target, array $site, array $pairs, array $reused): ?SiteRecord
    {
        $title = TitleNormaliser::normalise((string) $target->fields['title'], $this->settings->cancelPrefixes);
        $candidates = [];
        foreach ($site as $key => $record) {
            if (isset($reused[$key]) || $record->kind !== TargetRecord::WEEKLY_OFFER || $record->isPublished()) {
                continue;
            }
            $own = $pairs[$key] ?? null;
            if ($own !== null && !$own->past && $own->status === TargetRecord::PUBLISH) {
                // Its own series still runs; the draft will be published again for it.
                continue;
            }
            if ($record->weekday === null || $record->timeStart === null || $record->title === null) {
                continue;
            }
            if ($record->weekday !== (int) $target->fields['weekday'] || $record->timeStart !== $target->fields['time_start']) {
                continue;
            }
            if (TitleNormaliser::normalise($record->title, $this->settings->cancelPrefixes) !== $title) {
                continue;
            }
            $candidates[$key] = $record;
        }
        if ($candidates === []) {
            return null;
        }
        ksort($candidates, SORT_STRING);

        return reset($candidates);
    }

    /** @return array<string, array{0: bool, 1: bool}> */
    private function flagChanges(TargetRecord $target, SiteRecord $record): array
    {
        $names = $target->kind === TargetRecord::WEEKLY_OFFER ? ['ferienpause', 'abgesagt'] : ['cancelled'];
        $out = [];
        foreach ($names as $name) {
            if (!array_key_exists($name, $record->flags)) {
                continue;
            }
            $before = (bool) $record->flags[$name];
            $after = (bool) $target->fields[$name];
            if ($before !== $after) {
                $out[$name] = [$before, $after];
            }
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

use EnterOnce\Core\Action;
use EnterOnce\Core\CalendarResult;
use EnterOnce\Core\Core;
use EnterOnce\Core\GermanDate;
use EnterOnce\Core\IntegrityError;
use EnterOnce\Core\Notice;
use EnterOnce\Core\SyncPlan;
use EnterOnce\Core\TargetRecord;
use EnterOnce\Core\TargetSet;
use EnterOnce\Core\Window;
use Throwable;

/**
 * One run of one source: lock, fetch, core (screen, expand, normalise, diff), apply through the
 * adapter unless dry run, store the occurrence list and the state, notify, purge the cache.
 * A failed run changes nothing on the site (R-03).
 */
final class Runner
{
    /** Default time budget of one run in seconds (filter `enteronce_time_budget`). */
    public const TIME_BUDGET = 45;
    /** The lock outlives the time budget by this margin (fetching, storing, mailing). */
    public const LOCK_MARGIN = 120;

    private readonly int $budget;

    public function __construct(
        private readonly Clock $clock,
        private readonly Fetcher $fetcher,
        private readonly ?Notifier $notifier = null,
        ?int $timeBudget = null,
    ) {
        $this->budget = max(0, $timeBudget ?? (int) apply_filters('enteronce_time_budget', self::TIME_BUDGET));
    }

    /** @return list<RunReport> */
    public function runAll(string $trigger = 'cron'): array
    {
        $reports = [];
        foreach (Options::get()['sources'] as $source) {
            if (!empty($source['enabled'])) {
                $reports[] = $this->run((string) $source['id'], $trigger);
            }
        }

        return $reports;
    }

    /**
     * @param bool $releaseHeld apply a held run whatever it now contains (WP-CLI `--release-held`)
     * @param string|null $releaseHash apply a held run only if its list still has this hash («Trotzdem anwenden»)
     */
    public function run(string $sourceId, string $trigger = 'manual', bool $releaseHeld = false, ?string $releaseHash = null): RunReport
    {
        $settings = Options::get();
        $source = Options::source($settings, $sourceId);
        if ($source === null) {
            return new RunReport($sourceId, RunReport::SKIPPED, error: __('Unbekanntes Zentrum.', 'enter-once-sync'));
        }
        if (empty($source['enabled']) && $trigger === 'cron') {
            return new RunReport($sourceId, RunReport::SKIPPED);
        }
        if (!$this->lock($sourceId)) {
            return new RunReport($sourceId, RunReport::LOCKED, error: __('Ein anderer Abgleich läuft gerade.', 'enter-once-sync'));
        }
        $now = $this->clock->now();
        try {
            return $this->runLocked($settings, $source, $trigger, $releaseHeld, $releaseHash);
        } catch (Throwable $e) {
            [$category, $message] = match (true) {
                $e instanceof FetchError => ['fetch', $e->getMessage()],
                $e instanceof IntegrityError => ['integrity:' . $e->reason, $e->germanMessage()],
                default => ['internal', __('Interner Fehler beim Abgleich.', 'enter-once-sync') . ' ' . get_class($e) . ': ' . $e->getMessage()],
            };
            $message = Secret::scrub($message, (string) $source['ics_url']);
            State::merge($sourceId, [
                'last_run' => $now->format(DATE_ATOM),
                'trigger' => $trigger,
                'error' => $message,
                'error_time' => $now->format(DATE_ATOM),
            ]);
            $this->notifier?->error($sourceId, $message, $category);

            return new RunReport($sourceId, RunReport::ERROR, error: $message);
        } finally {
            $this->unlock($sourceId);
        }
    }

    /** Hash of a held list: the keys of the held records, sorted («Trotzdem anwenden» is bound to it). */
    public static function heldHash(SyncPlan $plan): string
    {
        $keys = array_map(static fn (Action $a) => $a->key, $plan->ofType(Action::HOLD));
        sort($keys);

        return md5(implode("\n", $keys));
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $source
     */
    private function runLocked(array $settings, array $source, string $trigger, bool $releaseHeld, ?string $releaseHash): RunReport
    {
        $started = microtime(true);
        $sourceId = (string) $source['id'];
        $now = $this->clock->now();
        $state = State::get($sourceId);

        $ics = $this->fetcher->fetch((string) $source['ics_url']);
        $core = new Core($this->coreSettings($settings), $sourceId);
        $window = Window::monthsAhead($now, (int) $settings['window_months_ahead']);
        $result = $core->read($ics, $now, $window, (int) ($state['last_event_count'] ?? 0) > 0);
        $targets = $core->targets($result);

        $map = Options::fieldMap($settings);
        $adapter = new EventAdapter($settings, $map, new TermResolver(), new Images($settings));
        $siteRecords = $adapter->siteRecords($sourceId);
        $plan = $core->diff($targets, $siteRecords);
        $releaseRefused = null;
        if ($plan->held && ($releaseHeld || $releaseHash !== null)) {
            if ($releaseHash !== null && !hash_equals(self::heldHash($plan), $releaseHash)) {
                $releaseRefused = __('Die Liste der angehaltenen Einträge hat sich seit der Anzeige geändert. Es wurde nichts entfernt. Bitte prüfen Sie die neue Liste und klicken Sie erneut auf «Trotzdem anwenden».', 'enter-once-sync');
            } else {
                $plan = $core->diff($targets, $siteRecords, true);
            }
        }
        // R-11: dry run while the mapping is incomplete or unverified; R-31: per source.
        $dryRun = $plan->dryRun || !$map->isComplete();

        $changed = [];
        $remaining = 0;
        if (!$dryRun) {
            $categories = self::categoriesByKey($result);
            $applied = 0;
            foreach ($plan->actions as $action) {
                // Review D2: stop cleanly between actions when the time budget is used up; the next run resumes.
                if ($applied > 0 && $action->isChange() && microtime(true) - $started > $this->budget) {
                    ++$remaining;
                    continue;
                }
                $id = $adapter->apply($action, $sourceId, $categories);
                if ($action->isChange()) {
                    ++$applied;
                }
                if ($id !== null) {
                    $changed[] = $id;
                }
            }
            Integrations::purgeCache($changed);
        }

        // The feed is served from this list (plan 4.4, 4.8). Review D7: a held run keeps the previous
        // list (R-13), and occurrences that vanish stay for removal_grace_runs runs (R-05).
        $occurrences = array_map(static fn ($o) => $o->toArray(), $result->occurrences);
        if (!$plan->held) {
            $previous = State::occurrences($sourceId)['occurrences'] ?? [];
            State::storeOccurrences($sourceId, [
                'window' => $window->toArray(),
                'occurrences' => self::mergeOccurrences((array) $previous, $occurrences, (int) $settings['removal_grace_runs'], $window),
            ]);
        }

        $compact = self::compactActions($plan, $targets);
        $notices = array_merge(
            array_map(static fn (Notice $n) => $n->toArray(), $result->notices),
            self::textDifferences($plan, $sourceId),
            self::personNotices($adapter->skipped, $sourceId)
        );
        $held = array_values(array_filter($compact, static fn (array $a) => $a['type'] === Action::HOLD));
        State::merge($sourceId, [
            'last_run' => $now->format(DATE_ATOM),
            'last_success' => $now->format(DATE_ATOM),
            'trigger' => $trigger,
            'dry_run' => $dryRun,
            'error' => null,
            'last_event_count' => $result->eventCount,
            'summary' => $result->summary(),
            'counts' => $plan->counts(),
            'notices' => $notices,
            'actions' => array_values(array_filter($compact, static fn (array $a) => $a['type'] !== Action::KEEP)),
            'held' => $plan->held,
            'held_actions' => $held,
            'held_hash' => $plan->held ? self::heldHash($plan) : null,
            'release_refused' => $releaseRefused,
            'incomplete' => $remaining,
            'weekly_series' => self::weeklySeries($targets),
            'window' => $window->toArray(),
            'missing_terms' => array_values(array_unique($adapter->missingTerms)),
            'skipped' => $adapter->skipped,
        ] + ($trigger === 'cli' ? ['last_cli_run' => $now->format(DATE_ATOM)] : []));

        if ($this->notifier !== null) {
            if (!$dryRun) {
                $this->notifier->queue($sourceId, array_values(array_filter($compact, static fn (array $a) => in_array($a['type'], [Action::CREATE, Action::UPDATE, Action::UNPUBLISH], true))), $notices);
            } else {
                $this->notifier->queue($sourceId, [], $notices);
            }
            if ($plan->held) {
                $this->notifier->held($sourceId, $held);
            }
            $this->notifier->maybeSendDigest($sourceId);
        }

        return new RunReport($sourceId, RunReport::OK, $dryRun, $plan->held, $plan->counts(), $releaseRefused, $plan, $changed, $remaining);
    }

    /**
     * Occurrences of the previous list that are missing from the new one are kept for
     * removal_grace_runs - 1 further runs (the same grace as for records, R-05), unless they ended
     * before the window.
     *
     * @param list<array<string, mixed>> $previous
     * @param list<array<string, mixed>> $current
     * @return list<array<string, mixed>>
     */
    public static function mergeOccurrences(array $previous, array $current, int $graceRuns, Window $window): array
    {
        $present = [];
        foreach ($current as $row) {
            $present[(string) $row['sync_key']] = true;
        }
        $out = $current;
        foreach ($previous as $row) {
            if (!is_array($row) || isset($present[(string) ($row['sync_key'] ?? '')])) {
                continue;
            }
            try {
                $end = new \DateTimeImmutable((string) $row['end']);
            } catch (\Exception) {
                continue;
            }
            if ($end <= $window->start) {
                continue;
            }
            $missing = (int) ($row['_missing'] ?? 0) + 1;
            if ($missing < max(1, $graceRuns)) {
                $row['_missing'] = $missing;
                $out[] = $row;
            }
        }
        usort($out, static fn (array $a, array $b) => [strtotime((string) $a['start']), $a['sync_key']] <=> [strtotime((string) $b['start']), $b['sync_key']]);

        return $out;
    }

    /**
     * Records the sync may not change (bin, private, pending, scheduled): a person must act.
     *
     * @param list<string> $messages
     * @return list<array<string, mixed>>
     */
    private static function personNotices(array $messages, string $sourceId): array
    {
        return array_map(static fn (string $m) => [
            'level' => Notice::WARNING,
            'code' => 'needs_person',
            'source_id' => $sourceId,
            'uid' => md5($m),
            'title' => '',
            'field' => null,
            'value' => null,
            'corrected_to' => null,
            'message' => $m,
        ], array_values(array_unique($messages)));
    }

    /**
     * The settings array the core reads: the stored settings with the source's entry.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function coreSettings(array $settings): array
    {
        $core = $settings;
        $core['sources'] = array_map(static fn (array $s) => ['id' => $s['id'], 'label' => $s['label'], 'location_term' => (int) $s['location_term'] ?: null, 'enabled' => (bool) $s['enabled'], 'dry_run' => (bool) $s['dry_run']], $settings['sources']);

        return $core;
    }

    /** @return array<string, list<string>> */
    private static function categoriesByKey(CalendarResult $result): array
    {
        $out = [];
        foreach ($result->occurrences as $o) {
            $out[$o->syncKey] = $o->categories;
        }
        foreach ($result->series as $s) {
            foreach ($s->weekdays as $d) {
                $out[$s->syncKey() . ':' . GermanDate::code($d)] = $s->fields->categories;
            }
        }

        return $out;
    }

    /**
     * Plan 4.7: for adopted records that keep the site's text, differences between calendar and
     * site appear in the digest (as info notices, code `text_differs`).
     *
     * @return list<array<string, mixed>>
     */
    private static function textDifferences(SyncPlan $plan, string $sourceId): array
    {
        $out = [];
        foreach ($plan->actions as $a) {
            if ($a->target === null || $a->siteId === null || get_post_meta((int) $a->siteId, EventAdapter::META_KEEP_TEXT, true) !== '1') {
                continue;
            }
            $site = html_entity_decode(get_the_title((int) $a->siteId), ENT_QUOTES, 'UTF-8');
            $calendar = (string) $a->target->fields['title'];
            $text = static fn (string $html): string => trim(wp_strip_all_tags(\EnterOnce\Core\MovedDateNote::strip($html)));
            if ($site === $calendar && $text((string) get_post_field('post_content', (int) $a->siteId, 'raw')) === $text((string) $a->target->fields['body'])) {
                continue;
            }
            $out[] = [
                'level' => Notice::INFO,
                'code' => 'text_differs',
                'source_id' => $sourceId,
                'uid' => $a->target->uid,
                'title' => $site,
                'field' => null,
                'value' => $calendar,
                'corrected_to' => null,
                'message' => $site === $calendar
                    ? sprintf(__('«%s»: Der Text auf der Website weicht vom Kalender ab (Text der Website wird behalten).', 'enter-once-sync'), $site)
                    : sprintf(__('«%1$s»: Titel im Kalender lautet «%2$s» (Titel und Text der Website werden behalten).', 'enter-once-sync'), $site, $calendar),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> one line per action, for the status page and the digest */
    public static function compactActions(SyncPlan $plan, TargetSet $targets): array
    {
        $out = [];
        foreach ($plan->actions as $a) {
            $t = $a->target ?? $targets->byKey($a->key);
            $title = $t !== null ? (string) $t->fields['title'] : '';
            if ($title === '' && $a->siteId !== null) {
                $title = html_entity_decode(get_the_title((int) $a->siteId), ENT_QUOTES, 'UTF-8');
            }
            $out[] = [
                'type' => $a->type,
                'kind' => $a->kind,
                'key' => $a->key,
                'site_key' => $a->siteKey,
                'reason' => $a->reason,
                'post_id' => $a->siteId,
                'title' => $title,
                'when' => $t !== null ? self::when($t) : '',
                'note' => $t !== null && $t->kind === TargetRecord::WEEKLY_OFFER ? $t->fields['note'] : null,
                'flag_changes' => $a->flagChanges,
            ];
        }

        return $out;
    }

    /** «Mo, 07.12.2026, 09:00–10:00» or «Jeden Montag, 09:00–10:00». */
    public static function when(TargetRecord $t): string
    {
        $f = $t->fields;
        $time = $f['time_start'] !== null ? ', ' . $f['time_start'] . '–' . $f['time_end'] : '';
        if ($t->kind === TargetRecord::WEEKLY_OFFER) {
            return sprintf(__('Jeden %s', 'enter-once-sync'), GermanDate::weekdayName((int) $f['weekday'])) . $time;
        }
        $d = new \DateTimeImmutable((string) $f['date']);

        return $d->format('d.m.Y') . $time;
    }

    /** @return list<array<string, mixed>> weekly series of the last run, for adoption */
    private static function weeklySeries(TargetSet $targets): array
    {
        $out = [];
        foreach (array_merge($targets->ofKind(TargetRecord::WEEKLY_OFFER), $targets->pending) as $t) {
            $f = $t->fields;
            $out[] = [
                'key' => $t->key,
                'title' => $f['title'],
                'weekday' => $f['weekday'],
                'time_start' => $f['time_start'],
                'time_end' => $f['time_end'],
                'first_date' => $f['first_date'],
                'last_date' => $f['last_date'],
                'status' => $t->status,
                'pending' => in_array($t, $targets->pending, true),
                'topics' => $f['topics'],
                'target_groups' => $f['target_groups'],
                'body' => $f['body'],
                'room' => $f['room'],
            ];
        }

        return $out;
    }

    private function lock(string $sourceId): bool
    {
        $key = 'enteronce_lock_' . $sourceId;
        $now = time();
        $ttl = $this->budget + self::LOCK_MARGIN;
        if (add_option($key, (string) ($now + $ttl), '', false)) {
            return true;
        }
        $until = (int) get_option($key, '0');
        if ($until < $now) {
            delete_option($key);

            return add_option($key, (string) ($now + $ttl), '', false);
        }

        return false;
    }

    private function unlock(string $sourceId): void
    {
        delete_option('enteronce_lock_' . $sourceId);
    }
}

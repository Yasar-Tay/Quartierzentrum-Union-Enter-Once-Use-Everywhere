<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One record on the site, as the shell reads it, in the form the diff needs.
 *
 * Built by the shell from the post and its `_enteronce_*` meta. Records that are not
 * managed or belong to another source may be passed in; the diff ignores them (R-04).
 */
final class SiteRecord
{
    /**
     * @param array<string, bool> $flags weekly offers: 'ferienpause', 'abgesagt'; dated events: 'cancelled' (informational)
     */
    public function __construct(
        /** `_enteronce_key` */
        public readonly string $key,
        /** TargetRecord::DATED_EVENT or TargetRecord::WEEKLY_OFFER */
        public readonly string $kind,
        /** `_enteronce_hash`, or null (never written by the sync, e.g. just adopted) */
        public readonly ?string $hash,
        /** 'publish' or 'draft' (any other WordPress status counts as draft) */
        public readonly string $status,
        /** `_enteronce_missing_runs` */
        public readonly int $missingRuns,
        /** `_enteronce_source` */
        public readonly string $source,
        /** `_enteronce_managed` = 1 */
        public readonly bool $managed = true,
        /** `_enteronce_keep_text` (A-14) */
        public readonly bool $keepText = false,
        public readonly array $flags = [],
        /** Start of the span the record covers (event start / first date of a weekly offer); null if unknown. */
        public readonly ?DateTimeImmutable $start = null,
        /** End of the span (event end / end of the last date of a weekly offer); null if unknown or open-ended. */
        public readonly ?DateTimeImmutable $end = null,
        /** Weekly offers, for R-27: current title on the site. */
        public readonly ?string $title = null,
        /** Weekly offers, for R-27: ISO weekday 1–7. */
        public readonly ?int $weekday = null,
        /** Weekly offers, for R-27: start time "HH:MM". */
        public readonly ?string $timeStart = null,
        /** The shell's own id for the record (post ID); passed through to actions untouched. */
        public readonly int|string|null $id = null,
    ) {
    }

    /**
     * Build from an array with the keys of {@see SiteRecord::toArray()}.
     *
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        foreach (['key', 'kind', 'status', 'source'] as $required) {
            if (!isset($a[$required])) {
                throw new InvalidArgumentException("Site record lacks '$required'.");
            }
        }
        $date = static fn ($v) => is_string($v) && $v !== '' ? new DateTimeImmutable($v, Settings::timezone()) : null;

        return new self(
            (string) $a['key'],
            (string) $a['kind'],
            isset($a['hash']) ? (string) $a['hash'] : null,
            (string) $a['status'],
            (int) ($a['missing_runs'] ?? 0),
            (string) $a['source'],
            (bool) ($a['managed'] ?? true),
            (bool) ($a['keep_text'] ?? false),
            array_map('boolval', (array) ($a['flags'] ?? [])),
            $date($a['start'] ?? null),
            $date($a['end'] ?? null),
            isset($a['title']) ? (string) $a['title'] : null,
            isset($a['weekday']) ? (int) $a['weekday'] : null,
            isset($a['time_start']) ? (string) $a['time_start'] : null,
            $a['id'] ?? null,
        );
    }

    public function isPublished(): bool
    {
        return $this->status === TargetRecord::PUBLISH;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind,
            'hash' => $this->hash,
            'status' => $this->status,
            'missing_runs' => $this->missingRuns,
            'source' => $this->source,
            'managed' => $this->managed,
            'keep_text' => $this->keepText,
            'flags' => $this->flags,
            'start' => $this->start?->format(DATE_ATOM),
            'end' => $this->end?->format(DATE_ATOM),
            'title' => $this->title,
            'weekday' => $this->weekday,
            'time_start' => $this->timeStart,
            'id' => $this->id,
        ];
    }

    /**
     * The site record the shell stores after applying a create or update for $target.
     * Useful for tests and for the dry-run simulation of a second run.
     */
    public static function fromTarget(TargetRecord $target, bool $keepText = false, int|string|null $id = null): self
    {
        $f = $target->fields;
        $flags = $target->kind === TargetRecord::WEEKLY_OFFER
            ? ['ferienpause' => (bool) $f['ferienpause'], 'abgesagt' => (bool) $f['abgesagt']]
            : ['cancelled' => (bool) $f['cancelled']];

        return new self(
            $target->key,
            $target->kind,
            $target->hashFor($keepText),
            $target->status,
            0,
            $target->sourceId,
            true,
            $keepText,
            $flags,
            $target->start,
            $target->end,
            (string) $f['title'],
            $target->kind === TargetRecord::WEEKLY_OFFER ? (int) $f['weekday'] : null,
            $target->kind === TargetRecord::WEEKLY_OFFER ? $f['time_start'] : null,
            $id,
        );
    }
}

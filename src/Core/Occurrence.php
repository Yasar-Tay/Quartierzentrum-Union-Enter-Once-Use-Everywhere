<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use DateTimeImmutable;

/**
 * One date of one event (plan section 4.4). Times are in Europe/Zurich.
 *
 * Cancelled occurrences are kept in the list with `cancelled = true`; consumers
 * decide by `cancel_mode` whether to show them.
 */
final class Occurrence
{
    /**
     * @param list<string> $categories calendar names, untranslated
     * @param list<string> $tags calendar names, untranslated
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $uid,
        /** `source:uid` for a single event, `source:uid:YYYY-MM-DD` (original local date) for a series date */
        public readonly string $syncKey,
        public readonly string $title,
        public readonly DateTimeImmutable $start,
        /** Exclusive end; for all-day entries midnight after the last day. */
        public readonly DateTimeImmutable $end,
        public readonly bool $allDay,
        public readonly string $room,
        public readonly string $location,
        public readonly string $description,
        public readonly string $descriptionHtml,
        public readonly array $categories,
        public readonly array $tags,
        public readonly bool $cancelled,
        /** UID of the series, or null for a single event. */
        public readonly ?string $seriesUid,
        /** Original local date (YYYY-MM-DD) of a series date, or null. */
        public readonly ?string $originalDate,
        /** True when this series date differs in time, day or room from the series' regular pattern. */
        public readonly bool $moved,
    ) {
    }

    /** `source:uid` of the series, or null. */
    public function seriesKey(): ?string
    {
        return $this->seriesUid === null ? null : $this->sourceId . ':' . $this->seriesUid;
    }

    /**
     * Rebuild from {@see Occurrence::toArray()} output (the stored list `enteronce_occurrences_<source_id>`).
     *
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        $tz = Settings::timezone();

        return new self(
            (string) $a['source_id'],
            (string) $a['uid'],
            (string) $a['sync_key'],
            (string) $a['title'],
            (new DateTimeImmutable((string) $a['start']))->setTimezone($tz),
            (new DateTimeImmutable((string) $a['end']))->setTimezone($tz),
            (bool) $a['all_day'],
            (string) ($a['room'] ?? ''),
            (string) ($a['location'] ?? ''),
            (string) ($a['description'] ?? ''),
            (string) ($a['description_html'] ?? ''),
            array_values(array_map('strval', (array) ($a['categories'] ?? []))),
            array_values(array_map('strval', (array) ($a['tags'] ?? []))),
            (bool) ($a['cancelled'] ?? false),
            isset($a['series_uid']) ? (string) $a['series_uid'] : null,
            isset($a['original_date']) ? (string) $a['original_date'] : null,
            (bool) ($a['moved'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_id' => $this->sourceId,
            'uid' => $this->uid,
            'sync_key' => $this->syncKey,
            'title' => $this->title,
            'start' => $this->start->format(DATE_ATOM),
            'end' => $this->end->format(DATE_ATOM),
            'all_day' => $this->allDay,
            'room' => $this->room,
            'location' => $this->location,
            'description' => $this->description,
            'description_html' => $this->descriptionHtml,
            'categories' => $this->categories,
            'tags' => $this->tags,
            'cancelled' => $this->cancelled,
            'series_uid' => $this->seriesUid,
            'series_key' => $this->seriesKey(),
            'original_date' => $this->originalDate,
            'moved' => $this->moved,
        ];
    }
}

<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use DateTimeImmutable;

/**
 * One recurring event as a whole (plan section 4.4). Produced for every series.
 *
 * Date statistics cover the whole series, not only the window, as far as the
 * series is finite. For an open-ended series the pattern is generated up to the
 * expansion horizon, and `last_date` / `date_count` are null.
 */
final class SeriesDefinition
{
    /**
     * @param list<int> $weekdays ISO weekday numbers of the pattern
     * @param list<Slot> $slots the regular pattern plus extra changed dates, sorted by original start
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $uid,
        public readonly EntryFields $fields,
        public readonly string $freq,
        public readonly int $interval,
        public readonly string $rrule,
        /** FREQ=WEEKLY, INTERVAL=1, no RDATE: candidate for weekly offers (R-20). */
        public readonly bool $weekly,
        public readonly array $weekdays,
        public readonly bool $allDay,
        public readonly DateTimeImmutable $patternStart,
        public readonly DateTimeImmutable $patternEnd,
        public readonly bool $openEnded,
        public readonly array $slots,
        /** Two dates of the series on one original local day: warning, not synced (section 4.4). */
        public readonly bool $conflict,
    ) {
    }

    public function syncKey(): string
    {
        return $this->sourceId . ':' . $this->uid;
    }

    /**
     * Slots that take place (not skipped), optionally for one ISO weekday.
     *
     * @return list<Slot>
     */
    public function datedSlots(?int $weekday = null): array
    {
        return array_values(array_filter(
            $this->slots,
            static fn (Slot $s) => $s->takesPlace() && ($weekday === null || $s->weekday() === $weekday)
        ));
    }

    /**
     * Statistics per weekday (R-20, R-23, R-26).
     *
     * @return array{iso: int, code: string, name: string, first_date: ?string, last_date: ?string, date_count: ?int}
     */
    public function weekdayStats(int $weekday): array
    {
        $dated = $this->datedSlots($weekday);
        $first = $dated[0] ?? null;
        $last = $dated === [] ? null : $dated[count($dated) - 1];

        return [
            'iso' => $weekday,
            'code' => GermanDate::code($weekday),
            'name' => GermanDate::weekdayName($weekday),
            'first_date' => $first?->originalDate,
            'last_date' => $this->openEnded ? null : $last?->originalDate,
            'date_count' => $this->openEnded ? null : count($dated),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $dated = $this->datedSlots();
        $first = $dated[0] ?? null;
        $last = $dated === [] ? null : $dated[count($dated) - 1];
        $skipped = [];
        $moved = [];
        $cancelled = [];
        foreach ($this->slots as $slot) {
            if ($slot->status === Slot::SKIPPED) {
                $skipped[] = $slot->originalDate;
            } elseif ($slot->status === Slot::CANCELLED) {
                $cancelled[] = $slot->originalDate;
            } elseif ($slot->status === Slot::MOVED) {
                $moved[] = [
                    'original_date' => $slot->originalDate,
                    'start' => $slot->actualStart?->format(DATE_ATOM),
                    'end' => $slot->actualEnd?->format(DATE_ATOM),
                    'room' => $slot->fields?->room,
                ];
            }
        }
        $f = $this->fields;
        $weekdayStats = array_map(fn (int $d) => $this->weekdayStats($d), $this->weekdays);

        return [
            'source_id' => $this->sourceId,
            'uid' => $this->uid,
            'sync_key' => $this->syncKey(),
            'title' => $f->title,
            'cancelled' => $f->cancelled,
            'rrule' => $this->rrule,
            'freq' => $this->freq,
            'interval' => $this->interval,
            'weekly' => $this->weekly,
            'weekdays' => $weekdayStats,
            'all_day' => $this->allDay,
            'time_start' => $this->allDay ? null : $this->patternStart->format('H:i'),
            'time_end' => $this->allDay ? null : $this->patternEnd->format('H:i'),
            'first_date' => $first?->originalDate,
            'last_date' => $this->openEnded ? null : $last?->originalDate,
            'date_count' => $this->openEnded ? null : count($dated),
            'open_ended' => $this->openEnded,
            'skipped_dates' => $skipped,
            'moved_dates' => $moved,
            'cancelled_dates' => $cancelled,
            'synced' => !$this->conflict,
            'room' => $f->room,
            'location' => $f->location,
            'description' => $f->description,
            'description_html' => $f->descriptionHtml,
            'categories' => $f->categories,
            'tags' => $f->tags,
        ];
    }
}

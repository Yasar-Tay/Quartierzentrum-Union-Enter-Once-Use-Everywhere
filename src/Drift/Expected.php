<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use EnterOnce\Core\GermanDate;

/**
 * One record the calendar says should be on the programme page: a dated event or a weekly
 * offer, as the core's target rules produce it. Holds only title, times and flags; never
 * description or any line naming a person (R-12).
 */
final class Expected
{
    public function __construct(
        public readonly string $kind,
        /** The core's record key (stable within a run, used in tests). */
        public readonly string $key,
        public readonly string $title,
        /** YYYY-MM-DD: the day of a dated event; for a weekly offer its first date. */
        public readonly ?string $date,
        /** YYYY-MM-DD last day of a multi-day dated event. */
        public readonly ?string $endDate,
        /** ISO weekday 1-7 (weekly offers). */
        public readonly ?int $weekday,
        public readonly ?string $timeStart,
        public readonly ?string $timeEnd,
        public readonly bool $allDay,
        /** Dated event cancelled; weekly offer: the next planned date (or the whole series) is cancelled. */
        public readonly bool $cancelled,
        /** Weekly offer: the next planned date is skipped (Ferienpause). */
        public readonly bool $vacation,
        /** Weekly offer that the site is not expected to show yet (R-26): may be matched, never reported missing. */
        public readonly bool $pending = false,
        public readonly string $room = '',
        /** YYYY-MM-DD: the next planned date of a weekly offer. */
        public readonly ?string $nextDate = null,
    ) {
    }

    public function isWeekly(): bool
    {
        return $this->kind === PageEvent::WEEKLY;
    }

    /** «Montag 09:00–11:00 Uhr» or «Sonntag, 29.11.2026 09:00–14:00 Uhr». */
    public function whenText(): string
    {
        if ($this->isWeekly()) {
            $day = $this->weekday === null ? '' : GermanDate::weekdayName($this->weekday);
        } else {
            $day = $this->date === null ? '' : Format::date($this->date);
            if ($this->endDate !== null && $this->endDate !== $this->date) {
                $day .= ' bis ' . Format::date($this->endDate);
            }
        }
        $time = $this->allDay ? 'ganztägig' : Format::times($this->timeStart, $this->timeEnd);

        return trim($day . ' ' . $time);
    }
}

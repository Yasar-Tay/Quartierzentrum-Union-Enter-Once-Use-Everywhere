<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use EnterOnce\Core\GermanDate;

/** One record as the programme page shows it: a dated event or a weekly offer. */
final class PageEvent
{
    public const DATED = 'dated';
    public const WEEKLY = 'weekly';

    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly ?string $url,
        /** YYYY-MM-DD (dated events). */
        public readonly ?string $date,
        /** YYYY-MM-DD, only when the page shows a second day. */
        public readonly ?string $endDate,
        /** ISO weekday 1-7 (weekly offers). */
        public readonly ?int $weekday,
        /** HH:MM, null when the page shows no time. */
        public readonly ?string $timeStart,
        public readonly ?string $timeEnd,
        /** Status band «abgesagt». */
        public readonly bool $cancelled,
        /** Status band «Ferienpause». */
        public readonly bool $vacation,
        /** Position on the page, starting at 0 within its kind. */
        public readonly int $index,
    ) {
    }

    public function isWeekly(): bool
    {
        return $this->kind === self::WEEKLY;
    }

    /** «Montag» or «So., 29.11.2026» style text for the report. */
    public function whenText(): string
    {
        $day = '';
        if ($this->isWeekly() && $this->weekday !== null) {
            $day = GermanDate::weekdayName($this->weekday);
        } elseif ($this->date !== null) {
            $day = Format::date($this->date);
            if ($this->endDate !== null) {
                $day .= ' bis ' . Format::date($this->endDate);
            }
        }

        return trim($day . ' ' . Format::times($this->timeStart, $this->timeEnd));
    }
}

<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use DateTimeImmutable;
use EnterOnce\Core\Window;

/** Everything the report says about the run itself (not about differences). */
final class ReportContext
{
    /**
     * @param array<string, int> $calendarSummary counts from CalendarSide::summary()
     */
    public function __construct(
        /** When the report was made (the real clock). */
        public readonly DateTimeImmutable $generatedAt,
        /** The "now" the calendar was read with. */
        public readonly DateTimeImmutable $calendarNow,
        public readonly Window $window,
        public readonly string $centreName,
        public readonly int $categoryId,
        /** A label for the calendar: a file name, never an address (it may be secret). */
        public readonly string $calendarLabel,
        /** The programme page address, or «Datei: name». */
        public readonly string $pageLabel,
        /** The public programme page address for the link at the top, or null for a saved file. */
        public readonly ?string $pageUrl,
        public readonly PageSnapshot $page,
        public readonly array $calendarSummary,
    ) {
    }
}

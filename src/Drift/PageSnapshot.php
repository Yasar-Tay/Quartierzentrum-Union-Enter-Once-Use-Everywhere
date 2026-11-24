<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

/** What the reader found on one programme page. */
final class PageSnapshot
{
    /**
     * @param list<PageEvent> $datedEvents
     * @param list<PageEvent> $weeklyOffers
     * @param list<string>    $unreadable titles (or positions) of entries that could not be read
     */
    public function __construct(
        public readonly array $datedEvents,
        public readonly array $weeklyOffers,
        public readonly array $unreadable,
        /** A «load more» button for dated events is present: the list is longer than shown. */
        public readonly bool $loadMoreDated,
        /** A «load more» button for weekly offers is present. */
        public readonly bool $loadMoreWeekly,
        /** The page size of the site's list (data-perpage), 8 unless the page says otherwise. */
        public readonly int $perPage,
        public readonly bool $hasDatedBlock,
        public readonly bool $hasWeeklyBlock,
    ) {
    }

    /** More weekly offers may exist than the page showed. */
    public function weeklyMayBeIncomplete(): bool
    {
        return $this->loadMoreWeekly || count($this->weeklyOffers) >= $this->perPage;
    }

    /** More dated events may exist than the page showed. */
    public function datedMayBeIncomplete(): bool
    {
        return $this->loadMoreDated;
    }

    /** The last day shown among the dated events (they are listed by date), or null. */
    public function lastDatedDay(): ?string
    {
        $days = array_map(static fn (PageEvent $e) => $e->endDate ?? $e->date ?? '', $this->datedEvents);
        $days = array_filter($days);

        return $days === [] ? null : max($days);
    }
}

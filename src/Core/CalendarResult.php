<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use DateTimeImmutable;

/** Output of {@see Core::read()}: everything the calendar says, after screening, expansion and normalisation. */
final class CalendarResult
{
    /**
     * @param list<Occurrence> $occurrences all occurrences inside the window, cancelled ones included, sorted by start
     * @param list<SeriesDefinition> $series every series of the file (also those outside the window)
     * @param list<Notice> $notices once per event or series
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly DateTimeImmutable $now,
        public readonly Window $window,
        public readonly array $occurrences,
        public readonly array $series,
        public readonly array $notices,
        /** Entries (VEVENT components) dropped by R-01. */
        public readonly int $droppedInternal,
        /** VEVENT components in the file before screening (store it for R-03's "had events last time"). */
        public readonly int $eventCount,
    ) {
    }

    /** @return list<Occurrence> */
    public function activeOccurrences(): array
    {
        return array_values(array_filter($this->occurrences, static fn (Occurrence $o) => !$o->cancelled));
    }

    /** @return list<Occurrence> */
    public function cancelledOccurrences(): array
    {
        return array_values(array_filter($this->occurrences, static fn (Occurrence $o) => $o->cancelled));
    }

    /** @return list<Notice> */
    public function warnings(): array
    {
        return array_values(array_filter($this->notices, static fn (Notice $n) => $n->level === Notice::WARNING));
    }

    /** @return list<Notice> */
    public function infos(): array
    {
        return array_values(array_filter($this->notices, static fn (Notice $n) => $n->level === Notice::INFO));
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        $cancelledEvents = [];
        foreach ($this->cancelledOccurrences() as $o) {
            $cancelledEvents[$o->uid] = true;
        }

        return [
            'events_in_file' => $this->eventCount,
            'dropped_internal' => $this->droppedInternal,
            'occurrences_active' => count($this->activeOccurrences()),
            'occurrences_cancelled' => count($this->cancelledOccurrences()),
            'cancelled_events' => count($cancelledEvents),
            'series' => count($this->series),
            'warnings' => count($this->warnings()),
            'infos' => count($this->infos()),
        ];
    }
}

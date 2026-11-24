<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

/**
 * One calendar entry (or, for «one site offer covers several series», several) paired with one
 * record on the site, and what differs between them.
 */
final class Pair
{
    /**
     * @param list<Expected> $expected more than one only when a single site offer covers several series
     * @param list<string>   $differences German sentences; empty when the two agree
     */
    public function __construct(
        public readonly array $expected,
        public readonly PageEvent $site,
        public readonly array $differences,
        /** The calendar says cancelled; the site shows no «abgesagt». */
        public readonly bool $cancelledOnline = false,
    ) {
    }

    public function coversSeveralSeries(): bool
    {
        return count($this->expected) > 1;
    }
}

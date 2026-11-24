<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

/** The outcome of the comparison: the four lists of plan section 4.10 plus ambiguous and unverifiable entries. */
final class DriftResult
{
    /**
     * @param list<Expected>       $missing         in the calendar, not on the site
     * @param list<Pair>           $different       on both, something differs
     * @param list<PageEvent>      $onlyOnSite      on the site, not in the calendar
     * @param list<Pair>           $cancelledOnline cancelled in the calendar, still online
     * @param list<AmbiguousGroup> $ambiguous       could not be told apart
     * @param list<Expected>       $unverifiable    could not be judged: the page showed only part of the list
     * @param list<Pair>           $agreeing        on both, nothing differs
     * @param array<string, int>   $ignored         site records or calendar entries left out, with the reason as key
     */
    public function __construct(
        public readonly array $missing,
        public readonly array $different,
        public readonly array $onlyOnSite,
        public readonly array $cancelledOnline,
        public readonly array $ambiguous,
        public readonly array $unverifiable,
        public readonly array $agreeing,
        public readonly array $ignored,
    ) {
    }

    /** True when the report has something a person should look at. */
    public function hasFindings(): bool
    {
        return $this->missing !== [] || $this->different !== [] || $this->onlyOnSite !== []
            || $this->cancelledOnline !== [] || $this->ambiguous !== [];
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return [
            'missing' => count($this->missing),
            'different' => count($this->different),
            'only_on_site' => count($this->onlyOnSite),
            'cancelled_online' => count($this->cancelledOnline),
            'ambiguous' => count($this->ambiguous),
            'unverifiable' => count($this->unverifiable),
            'agreeing' => count($this->agreeing),
        ];
    }
}

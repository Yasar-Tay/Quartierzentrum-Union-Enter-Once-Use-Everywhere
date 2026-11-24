<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

/** Gets the programme page. Implementations: FilePageFetcher (trial runs, tests), HttpPageFetcher (real runs). */
interface PageFetcher
{
    /** @throws FetchError */
    public function fetch(string $url): PageResponse;

    /** True when a request leaves the machine; only then R-40's hourly limit and day stop apply. */
    public function isLive(): bool;
}

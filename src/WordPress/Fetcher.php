<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

/** Downloads the ICS text of a source. Replace it with the `enteronce_fetcher` filter or by injection. */
interface Fetcher
{
    /** @throws FetchError */
    public function fetch(string $url): string;
}

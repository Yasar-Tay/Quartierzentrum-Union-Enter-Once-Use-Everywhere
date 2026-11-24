<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

/** Calendar entries and site records that cannot be told apart: reported, never paired by guess. */
final class AmbiguousGroup
{
    /**
     * @param list<Expected>  $expected
     * @param list<PageEvent> $site
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $expected,
        public readonly array $site,
    ) {
    }
}

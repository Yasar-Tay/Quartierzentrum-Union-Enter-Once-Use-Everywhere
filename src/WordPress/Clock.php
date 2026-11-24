<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

use DateTimeImmutable;

/** The only source of "now" in the shell. Replace it with the `enteronce_clock` filter or by injection. */
interface Clock
{
    /** Current time in Europe/Zurich. */
    public function now(): DateTimeImmutable;
}

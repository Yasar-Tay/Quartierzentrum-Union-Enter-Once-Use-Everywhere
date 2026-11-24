<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use RuntimeException;

/** A transport failure: no answer, refused address, unreadable file. */
final class FetchError extends RuntimeException
{
}

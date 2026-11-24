<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

use RuntimeException;

/** The calendar could not be downloaded (R-02, R-03). The message is German and never contains the address. */
final class FetchError extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use RuntimeException;

/** The calendar text could not be obtained (file missing, address refused, download failed). */
final class IcsLoadError extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

use DateTimeImmutable;
use EnterOnce\Core\Settings as CoreSettings;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', CoreSettings::timezone());
    }
}

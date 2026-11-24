<?php

declare(strict_types=1);

namespace EnterOnce\Tests\WordPress\Support;

use DateTimeImmutable;
use EnterOnce\WordPress\Clock;
use EnterOnce\Core\Settings as CoreSettings;

/** A clock that says what it is told. For tests only (not shipped). */
final class FixedClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable|string $now)
    {
        $this->set($now);
    }

    public function set(DateTimeImmutable|string $now): void
    {
        $tz = CoreSettings::timezone();
        $this->now = is_string($now) ? new DateTimeImmutable($now, $tz) : $now->setTimezone($tz);
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}

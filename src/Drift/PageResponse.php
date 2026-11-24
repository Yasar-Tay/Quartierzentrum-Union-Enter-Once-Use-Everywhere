<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

/** What one request returned. */
final class PageResponse
{
    public function __construct(public readonly int $status, public readonly string $body)
    {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** R-40: 403 and 429 mean «stop for today». */
    public function isBlock(): bool
    {
        return $this->status === 403 || $this->status === 429;
    }
}

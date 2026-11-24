<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

/** The result of one run: success, or exactly one reason why it stopped, with a German message. */
final class RunOutcome
{
    public const OK = 'ok';
    public const USAGE = 'usage';
    public const CALENDAR_UNAVAILABLE = 'calendar_unavailable';
    public const CALENDAR_INVALID = 'calendar_invalid';
    public const TOO_SOON = 'too_soon';
    public const STOPPED_TODAY = 'stopped_today';
    public const STATE_UNREADABLE = 'state_unreadable';
    public const BLOCKED = 'blocked';
    public const HTTP_ERROR = 'http_error';
    public const FETCH_FAILED = 'fetch_failed';
    public const STRUCTURE = 'structure_not_recognised';
    public const OUTPUT_FAILED = 'output_failed';

    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?DriftResult $result = null,
        public readonly ?string $outputFile = null,
    ) {
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }
}

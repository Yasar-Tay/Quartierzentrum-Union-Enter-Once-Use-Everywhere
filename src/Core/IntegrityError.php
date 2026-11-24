<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use RuntimeException;

/**
 * The ICS text failed the integrity check (R-03). The run MUST change nothing.
 *
 * {@see IntegrityError::$reason} is one of the REASON_* constants; the message is
 * English and meant for logs, {@see IntegrityError::germanMessage()} for staff.
 */
final class IntegrityError extends RuntimeException
{
    public const REASON_EMPTY_TEXT = 'empty_text';
    public const REASON_NOT_A_CALENDAR = 'not_a_calendar';
    public const REASON_TRUNCATED = 'truncated';
    public const REASON_PARSE_ERROR = 'parse_error';
    public const REASON_NO_EVENTS = 'no_events';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public function germanMessage(): string
    {
        return match ($this->reason) {
            self::REASON_EMPTY_TEXT => 'Die Kalenderdatei ist leer.',
            self::REASON_NOT_A_CALENDAR => 'Die heruntergeladene Datei ist kein Kalender (BEGIN:VCALENDAR fehlt).',
            self::REASON_TRUNCATED => 'Die Kalenderdatei ist unvollständig (END:VCALENDAR fehlt).',
            self::REASON_PARSE_ERROR => 'Die Kalenderdatei kann nicht gelesen werden.',
            self::REASON_NO_EVENTS => 'Die Kalenderdatei enthält keine Termine mehr, beim letzten Abgleich aber schon.',
            default => 'Die Kalenderdatei ist fehlerhaft.',
        };
    }
}

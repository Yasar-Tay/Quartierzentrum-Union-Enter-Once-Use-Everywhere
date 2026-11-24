<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use DateTimeInterface;

/** German (Swiss) date words for user-facing texts. */
final class GermanDate
{
    public const WEEKDAYS = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];
    public const MONTHS = [1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'];
    /** ISO weekday number → iCalendar BYDAY code. */
    public const CODES = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];

    /** «7. Dezember» */
    public static function dayMonth(DateTimeInterface $date): string
    {
        return (int) $date->format('j') . '. ' . self::MONTHS[(int) $date->format('n')];
    }

    /** «Dienstag, 8. Dezember» */
    public static function weekdayDayMonth(DateTimeInterface $date): string
    {
        return self::WEEKDAYS[(int) $date->format('N')] . ', ' . self::dayMonth($date);
    }

    public static function weekdayName(int $iso): string
    {
        return self::WEEKDAYS[$iso] ?? '';
    }

    public static function code(int $iso): string
    {
        return self::CODES[$iso] ?? '';
    }
}

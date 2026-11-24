<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

use DateTimeImmutable;
use EnterOnce\Core\Settings as CoreSettings;

/**
 * Value formats the wizard can recognise, and how to write and read them.
 * The candidate lists are ordered; the wizard's sample values are chosen so that exactly
 * one candidate matches (23.04.2031, 07:45, 09:05, Sunday).
 */
final class FieldFormats
{
    public const DATE = ['Ymd', 'Y-m-d', 'd.m.Y', 'j.n.Y', 'd.m.y', 'd/m/Y', 'm/d/Y', 'Y/m/d', 'dmY', 'U'];
    public const TIME = ['H:i', 'Hi', 'H.i', 'G:i', 'G.i', 'H:i:s', 'g:i A', 'minutes', 'seconds'];
    public const WEEKDAY = ['N', 'w', 'de2', 'de2dot', 'de_long', 'en3', 'en_long', 'ical'];

    private const DE_LONG = [1 => 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
    private const DE2 = [1 => 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    private const EN3 = [1 => 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    private const EN_LONG = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    private const ICAL = [1 => 'MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    public static function encodeDate(string $ymd, string $format): string
    {
        $d = new DateTimeImmutable($ymd . ' 00:00', CoreSettings::timezone());

        return $format === 'U' ? (string) $d->getTimestamp() : $d->format($format);
    }

    public static function decodeDate(string $value, string $format): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if ($format === 'U') {
            if (!ctype_digit($value)) {
                return null;
            }

            return (new DateTimeImmutable('@' . $value))->setTimezone(CoreSettings::timezone())->format('Y-m-d');
        }
        $d = DateTimeImmutable::createFromFormat('!' . $format, $value, CoreSettings::timezone());
        if ($d === false || $d->format($format) !== $value) {
            return null;
        }

        return $d->format('Y-m-d');
    }

    /** @param string $hm "HH:MM" */
    public static function encodeTime(string $hm, string $format): string
    {
        [$h, $m] = array_map('intval', explode(':', $hm));

        return match ($format) {
            'minutes' => (string) ($h * 60 + $m),
            'seconds' => (string) (($h * 60 + $m) * 60),
            default => (new DateTimeImmutable(sprintf('2000-01-01 %02d:%02d:00', $h, $m)))->format($format),
        };
    }

    /** @return string|null "HH:MM" */
    public static function decodeTime(string $value, string $format): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if ($format === 'minutes' || $format === 'seconds') {
            if (!ctype_digit($value)) {
                return null;
            }
            $min = $format === 'minutes' ? (int) $value : intdiv((int) $value, 60);
            if ($min > 24 * 60) {
                return null;
            }

            return sprintf('%02d:%02d', intdiv($min, 60) % 24, $min % 60);
        }
        $d = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($d === false || $d->format($format) !== $value) {
            return null;
        }

        return $d->format('H:i');
    }

    public static function encodeWeekday(int $iso, string $format): string
    {
        return match ($format) {
            'N' => (string) $iso,
            'w' => (string) ($iso % 7),
            'de2' => self::DE2[$iso],
            'de2dot' => self::DE2[$iso] . '.',
            'de_long' => self::DE_LONG[$iso],
            'en3' => self::EN3[$iso],
            'en_long' => self::EN_LONG[$iso],
            'ical' => self::ICAL[$iso],
            default => (string) $iso,
        };
    }

    public static function decodeWeekday(string $value, string $format): ?int
    {
        $value = trim($value);
        for ($iso = 1; $iso <= 7; ++$iso) {
            if (strcasecmp(self::encodeWeekday($iso, $format), $value) === 0) {
                return $iso;
            }
        }

        return null;
    }

    /** @return list<string> formats in which $value means $ymd */
    public static function matchDate(string $value, string $ymd): array
    {
        return array_values(array_filter(self::DATE, static fn (string $f) => self::encodeDate($ymd, $f) === trim($value)));
    }

    /** @return list<string> */
    public static function matchTime(string $value, string $hm): array
    {
        return array_values(array_filter(self::TIME, static fn (string $f) => self::encodeTime($hm, $f) === trim($value)));
    }

    /** @return list<string> exact matches first, then case-insensitive ones */
    public static function matchWeekday(string $value, int $iso): array
    {
        $exact = array_filter(self::WEEKDAY, static fn (string $f) => self::encodeWeekday($iso, $f) === trim($value));
        if ($exact !== []) {
            return array_values($exact);
        }

        return array_values(array_filter(self::WEEKDAY, static fn (string $f) => strcasecmp(self::encodeWeekday($iso, $f), trim($value)) === 0));
    }
}

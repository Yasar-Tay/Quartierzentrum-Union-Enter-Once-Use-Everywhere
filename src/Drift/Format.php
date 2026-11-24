<?php

declare(strict_types=1);

namespace EnterOnce\Drift;

use DateTimeImmutable;
use DateTimeZone;
use EnterOnce\Core\GermanDate;

/** Small German formatting helpers shared by the matcher and the report. */
final class Format
{
    /** «Sonntag, 29.11.2026» from YYYY-MM-DD. */
    public static function date(string $ymd): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('Europe/Zurich'));
        if ($d === false) {
            return $ymd;
        }

        return GermanDate::weekdayName((int) $d->format('N')) . ', ' . $d->format('d.m.Y');
    }

    /** «09:00–14:00 Uhr», «09:00 Uhr», or '' when there is no time. */
    public static function times(?string $start, ?string $end): string
    {
        if ($start === null) {
            return '';
        }

        return $end !== null && $end !== $start ? "{$start}–{$end} Uhr" : "{$start} Uhr";
    }

    /** Minutes since midnight from HH:MM. */
    public static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }

    /**
     * [start, end) in minutes. An end of 00:00 (or earlier than the start) means midnight: 1440.
     *
     * @return array{int, int}
     */
    public static function span(string $start, ?string $end): array
    {
        $s = self::minutes($start);
        $e = $end === null ? $s : self::minutes($end);
        if ($e <= $s && $end !== null) {
            $e = 1440;
        }

        return [$s, $e];
    }
}

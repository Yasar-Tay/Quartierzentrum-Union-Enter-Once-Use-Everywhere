<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use DateTimeZone;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;
use Sabre\VObject\TimeZoneUtil;
use Throwable;

/**
 * Step 1 of the core: ICS text → parsed calendar, with the integrity check of R-03
 * and the time zone normalisation (Windows names → Europe/Zurich).
 */
final class IcsReader
{
    /**
     * Windows / Outlook zone names whose rules are Central European Time.
     * They are all rewritten to Europe/Zurich (identical offsets and DST rules since 1981).
     */
    private const CET_WINDOWS_NAMES = [
        'w. europe standard time',
        'central europe standard time',
        'central european standard time',
        'romance standard time',
        'w. europe',
        '(utc+01:00) amsterdam, berlin, bern, rome, stockholm, vienna',
    ];

    private const DATE_PROPERTIES = ['DTSTART', 'DTEND', 'RECURRENCE-ID', 'EXDATE', 'RDATE', 'DUE'];

    /**
     * @param bool $lastRunHadEvents true when the previous successful run of this source
     *                               saw at least one event; an event-less file is then an error (R-03)
     *
     * @throws IntegrityError
     */
    public static function read(string $icsText, bool $lastRunHadEvents = false): VCalendar
    {
        $text = self::stripBom($icsText);
        $trimmed = trim($text);
        if ($trimmed === '') {
            throw new IntegrityError(IntegrityError::REASON_EMPTY_TEXT, 'The ICS text is empty.');
        }
        if (stripos($trimmed, 'BEGIN:VCALENDAR') !== 0) {
            throw new IntegrityError(IntegrityError::REASON_NOT_A_CALENDAR, 'The text does not start with BEGIN:VCALENDAR.');
        }
        if (!preg_match('/END:VCALENDAR\s*$/i', $trimmed)) {
            throw new IntegrityError(IntegrityError::REASON_TRUNCATED, 'The text does not end with END:VCALENDAR.');
        }
        try {
            $calendar = Reader::read($trimmed, Reader::OPTION_FORGIVING);
        } catch (ParseException $e) {
            throw new IntegrityError(IntegrityError::REASON_PARSE_ERROR, 'ICS parse error: ' . $e->getMessage());
        } catch (Throwable $e) {
            throw new IntegrityError(IntegrityError::REASON_PARSE_ERROR, 'ICS parse error: ' . $e->getMessage());
        }
        if (!$calendar instanceof VCalendar) {
            throw new IntegrityError(IntegrityError::REASON_NOT_A_CALENDAR, 'The text is not an iCalendar object.');
        }
        $events = $calendar->select('VEVENT');
        if ($events === [] && $lastRunHadEvents) {
            throw new IntegrityError(IntegrityError::REASON_NO_EVENTS, 'The calendar has no events, but the last run had some.');
        }
        self::normaliseTimeZones($calendar);

        return $calendar;
    }

    /** Resolve a TZID to a PHP zone. Unknown names fall back to Europe/Zurich, never to the server zone. */
    public static function resolveTimeZone(string $tzid, ?VCalendar $calendar = null): DateTimeZone
    {
        $key = strtolower(trim($tzid));
        if (in_array($key, self::CET_WINDOWS_NAMES, true)) {
            return Settings::timezone();
        }
        if (in_array($tzid, DateTimeZone::listIdentifiers(), true)) {
            return new DateTimeZone($tzid);
        }
        try {
            return TimeZoneUtil::getTimeZone($tzid, $calendar, true);
        } catch (Throwable) {
            return Settings::timezone();
        }
    }

    /** Rewrite every TZID parameter to a PHP zone identifier, so expansion never depends on the server's default zone. */
    private static function normaliseTimeZones(VCalendar $calendar): void
    {
        foreach ($calendar->select('VEVENT') as $event) {
            foreach (self::DATE_PROPERTIES as $name) {
                foreach ($event->select($name) as $property) {
                    if (!isset($property['TZID'])) {
                        continue;
                    }
                    $zone = self::resolveTimeZone((string) $property['TZID'], $calendar);
                    $property['TZID'] = $zone->getName();
                }
            }
        }
    }

    private static function stripBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }
}

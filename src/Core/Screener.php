<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;

/**
 * Step 2 of the core: screening.
 *
 * R-01: an entry is dropped when it has a CLASS line whose value is not PUBLIC,
 * or a CATEGORIES value equal to one of `internal_markers.categories` (case-insensitive).
 * An entry without CLASS is public.
 *
 * R-12: every property that is not on a short allow-list is removed from the kept
 * entries before anything else reads them, so ORGANIZER, ATTENDEE, CONTACT and
 * vendor X- lines can never reach an output. Sub-components (VALARM) are removed too.
 */
final class Screener
{
    /** Properties the core reads. Everything else is removed (R-12). */
    public const ALLOWED_PROPERTIES = [
        'UID', 'SUMMARY', 'DTSTART', 'DTEND', 'DURATION', 'RRULE', 'RDATE', 'EXDATE',
        'RECURRENCE-ID', 'LOCATION', 'DESCRIPTION', 'STATUS', 'CLASS', 'CATEGORIES',
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @return array{groups: array<string, array{master: ?VEvent, overrides: list<VEvent>, internal_overrides: list<VEvent>}>, dropped: int}
     *         groups are keyed by UID, in file order
     */
    public function screen(VCalendar $calendar): array
    {
        $groups = [];
        $dropped = 0;
        $internalMasters = [];
        foreach ($calendar->select('VEVENT') as $event) {
            /** @var VEvent $event */
            $uid = isset($event->UID) ? trim((string) $event->UID) : '';
            if ($uid === '') {
                $uid = 'nouid-' . substr(sha1((string) ($event->SUMMARY ?? '') . '|' . (string) ($event->DTSTART ?? '')), 0, 16);
            }
            $isOverride = isset($event->{'RECURRENCE-ID'});
            $internal = $this->isInternal($event);
            self::strip($event);
            $groups[$uid] ??= ['master' => null, 'overrides' => [], 'internal_overrides' => []];
            if ($internal) {
                ++$dropped;
                if ($isOverride) {
                    // One internal date of a public series: that date is not published (treated as skipped).
                    $groups[$uid]['internal_overrides'][] = $event;
                } else {
                    $internalMasters[$uid] = true;
                }
                continue;
            }
            if ($isOverride) {
                $groups[$uid]['overrides'][] = $event;
            } elseif ($groups[$uid]['master'] === null) {
                $groups[$uid]['master'] = $event;
            } else {
                // A second master with the same UID is invalid ICS; it is treated as a further date.
                $groups[$uid]['overrides'][] = $event;
            }
        }
        // An internal master makes its whole series internal, including public-looking overrides.
        foreach (array_keys($internalMasters) as $uid) {
            $dropped += count($groups[$uid]['overrides']);
            unset($groups[$uid]);
        }
        foreach ($groups as $uid => $group) {
            if ($group['master'] === null && $group['overrides'] === []) {
                unset($groups[$uid]);
            }
        }

        return ['groups' => $groups, 'dropped' => $dropped];
    }

    public function isInternal(VEvent $event): bool
    {
        if ($this->settings->internalClassNotPublic && isset($event->CLASS)) {
            if (strtoupper(trim((string) $event->CLASS)) !== 'PUBLIC') {
                return true;
            }
        }
        $markers = array_map(static fn ($m) => mb_strtolower(trim($m)), $this->settings->internalCategories);
        foreach ($event->select('CATEGORIES') as $property) {
            foreach ($property->getParts() as $value) {
                if (in_array(mb_strtolower(trim((string) $value)), $markers, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function strip(VEvent $event): void
    {
        foreach ($event->children() as $child) {
            if ($child instanceof \Sabre\VObject\Component) {
                $event->remove($child);
                continue;
            }
            if (!in_array(strtoupper($child->name), self::ALLOWED_PROPERTIES, true)) {
                $event->remove($child);
            }
        }
    }
}

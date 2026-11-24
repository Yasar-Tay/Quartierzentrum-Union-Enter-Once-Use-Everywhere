<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;
use Sabre\VObject\Recur\RRuleIterator;
use Throwable;

/**
 * Step 3 and 4 of the core: expands one screened UID group into occurrences inside
 * the window, a series definition (for series) and the group's notices.
 *
 * Skipped dates (EXDATE), changed dates (RECURRENCE-ID: moved, cancelled or only
 * re-worded) and cancelled series are applied. "Now" and the window are parameters.
 */
final class Expander
{
    /** Hard cap on generated pattern dates per series, against runaway rules. */
    public const MAX_SLOTS = 5000;

    private readonly Mapper $mapper;
    private readonly \DateTimeZone $tz;

    public function __construct(private readonly Settings $settings)
    {
        $this->mapper = new Mapper($settings);
        $this->tz = Settings::timezone();
    }

    /**
     * @param array{master: ?VEvent, overrides: list<VEvent>, internal_overrides: list<VEvent>} $group
     * @return array{occurrences: list<Occurrence>, series: ?SeriesDefinition, notices: list<Notice>}
     */
    public function expand(string $uid, array $group, DateTimeImmutable $now, Window $window): array
    {
        $master = $group['master'];
        if ($master !== null && (isset($master->RRULE) || isset($master->RDATE))) {
            return $this->expandSeries($uid, $master, $group['overrides'], $group['internal_overrides'], $now, $window);
        }
        if ($master !== null) {
            return $this->expandSingle($uid, $master, $window);
        }

        return $this->expandOrphans($uid, $group['overrides'], $window);
    }

    /** @return array{occurrences: list<Occurrence>, series: ?SeriesDefinition, notices: list<Notice>} */
    private function expandSingle(string $uid, VEvent $event, Window $window): array
    {
        $times = $this->times($event);
        if ($times === null) {
            return ['occurrences' => [], 'series' => null, 'notices' => []];
        }
        [$start, $end, $allDay] = $times;
        $fields = EntryFields::fromComponent($event, $this->settings, $this->mapper);
        if (!$window->overlaps($start, $end)) {
            return ['occurrences' => [], 'series' => null, 'notices' => []];
        }
        $occurrence = $this->occurrence($uid, $this->settings->sourceId . ':' . $uid, $fields, $start, $end, $allDay, $fields->cancelled, null, null, false);

        return ['occurrences' => [$occurrence], 'series' => null, 'notices' => $fields->notices($this->settings->sourceId, $uid)];
    }

    /**
     * Changed dates whose series master is not in the file: each is treated as a series date.
     *
     * @param list<VEvent> $overrides
     * @return array{occurrences: list<Occurrence>, series: ?SeriesDefinition, notices: list<Notice>}
     */
    private function expandOrphans(string $uid, array $overrides, Window $window): array
    {
        $occurrences = [];
        $notices = [];
        foreach ($overrides as $event) {
            $times = $this->times($event);
            $original = $this->dateTime($event->{'RECURRENCE-ID'});
            if ($times === null || $original === null) {
                continue;
            }
            [$start, $end, $allDay] = $times;
            if (!$window->overlaps($start, $end)) {
                continue;
            }
            $fields = EntryFields::fromComponent($event, $this->settings, $this->mapper);
            $date = $original->format('Y-m-d');
            $occurrences[] = $this->occurrence($uid, $this->settings->sourceId . ':' . $uid . ':' . $date, $fields, $start, $end, $allDay, $fields->cancelled, $uid, $date, false);
            $notices = array_merge($notices, $fields->notices($this->settings->sourceId, $uid));
        }

        return ['occurrences' => $occurrences, 'series' => null, 'notices' => self::dedupe($notices)];
    }

    /**
     * @param list<VEvent> $overrides
     * @param list<VEvent> $internalOverrides
     * @return array{occurrences: list<Occurrence>, series: ?SeriesDefinition, notices: list<Notice>}
     */
    private function expandSeries(string $uid, VEvent $master, array $overrides, array $internalOverrides, DateTimeImmutable $now, Window $window): array
    {
        $times = $this->times($master);
        if ($times === null) {
            return ['occurrences' => [], 'series' => null, 'notices' => []];
        }
        [$patternStart, $patternEnd, $allDay] = $times;
        $length = $patternStart->diff($patternEnd);
        $masterFields = EntryFields::fromComponent($master, $this->settings, $this->mapper);

        $horizon = $window->end;
        $nearHorizon = $now->modify('+' . ($this->settings->weeklyLeadDays + 8) . ' days');
        if ($nearHorizon > $horizon) {
            $horizon = $nearHorizon;
        }

        // 1. Regular pattern (RRULE + RDATE), ignoring EXDATE.
        [$patternDates, $openEnded, $rruleParts] = $this->pattern($master, $patternStart, $horizon);

        // 2. Skipped dates and changed dates, keyed by timestamp of the original start.
        $exdates = [];
        foreach ($master->select('EXDATE') as $property) {
            foreach ($this->dateTimes($property) as $dt) {
                $exdates[$dt->getTimestamp()] = true;
                $exdates['d:' . $dt->format('Y-m-d')] = $allDay;
            }
        }
        $changed = [];
        foreach ($overrides as $event) {
            $rid = $this->dateTime($event->{'RECURRENCE-ID'} ?? null);
            if ($rid !== null) {
                $changed[$rid->getTimestamp()] = $event;
            }
        }
        $internal = [];
        foreach ($internalOverrides as $event) {
            $rid = $this->dateTime($event->{'RECURRENCE-ID'} ?? null);
            if ($rid !== null) {
                $internal[$rid->getTimestamp()] = true;
            }
        }

        // 3. Slots.
        $slots = [];
        foreach ($patternDates as $ts => $originalStart) {
            $originalEnd = $originalStart->add($length);
            $date = $originalStart->format('Y-m-d');
            if (isset($exdates[$ts]) || (!empty($exdates['d:' . $date]) && $allDay) || isset($internal[$ts])) {
                $slots[] = new Slot($date, $originalStart, $originalEnd, Slot::SKIPPED, null, null, null);
                continue;
            }
            if (isset($changed[$ts])) {
                $slots[] = $this->changedSlot($changed[$ts], $date, $originalStart, $originalEnd, $length, $masterFields, false);
                unset($changed[$ts]);
                continue;
            }
            $status = $masterFields->cancelled ? Slot::CANCELLED : Slot::REGULAR;
            $slots[] = new Slot($date, $originalStart, $originalEnd, $status, $originalStart, $originalEnd, $masterFields);
        }
        // Changed dates that are not part of the pattern are kept as extra dates.
        foreach ($changed as $event) {
            $originalStart = $this->dateTime($event->{'RECURRENCE-ID'});
            if ($originalStart === null) {
                continue;
            }
            $slots[] = $this->changedSlot($event, $originalStart->format('Y-m-d'), $originalStart, $originalStart->add($length), $length, $masterFields, true);
        }
        usort($slots, static fn (Slot $a, Slot $b) => $a->originalStart <=> $b->originalStart);

        // 4. Two dates on one original day collide on the sync key: warning, series not synced.
        $perDay = [];
        foreach ($slots as $slot) {
            if ($slot->takesPlace()) {
                $perDay[$slot->originalDate] = ($perDay[$slot->originalDate] ?? 0) + 1;
            }
        }
        $collisions = array_keys(array_filter($perDay, static fn (int $n) => $n > 1));
        $conflict = $collisions !== [];

        $weekdays = $this->weekdays($rruleParts, $patternStart);
        $freq = strtoupper((string) ($rruleParts['FREQ'] ?? ''));
        $interval = max(1, (int) ($rruleParts['INTERVAL'] ?? 1));
        $series = new SeriesDefinition(
            $this->settings->sourceId,
            $uid,
            $masterFields,
            $freq,
            $interval,
            isset($master->RRULE) ? (string) $master->RRULE->getValue() : '',
            $freq === 'WEEKLY' && $interval === 1 && !isset($master->RDATE),
            $weekdays,
            $allDay,
            $patternStart,
            $patternEnd,
            $openEnded,
            $slots,
            $conflict,
        );

        if ($conflict) {
            $notice = new Notice(
                Notice::WARNING,
                Notice::CODE_TWO_DATES_ONE_DAY,
                $this->settings->sourceId,
                $uid,
                $masterFields->title,
                sprintf('«%s»: die Serie hat zwei Termine am selben Tag (%s) und wird nicht abgeglichen.', $masterFields->title, implode(', ', $collisions)),
                implode(', ', $collisions),
            );

            return ['occurrences' => [], 'series' => $series, 'notices' => $masterFields->cancelled ? [] : [$notice]];
        }

        $occurrences = [];
        $activeInWindow = false;
        foreach ($slots as $slot) {
            if (!$slot->takesPlace() || $slot->fields === null || $slot->actualStart === null || $slot->actualEnd === null) {
                continue;
            }
            if (!$window->overlaps($slot->actualStart, $slot->actualEnd)) {
                continue;
            }
            $cancelled = $slot->status === Slot::CANCELLED;
            $activeInWindow = $activeInWindow || !$cancelled;
            $occurrences[] = $this->occurrence(
                $uid,
                $this->settings->sourceId . ':' . $uid . ':' . $slot->originalDate,
                $slot->fields,
                $slot->actualStart,
                $slot->actualEnd,
                $allDay,
                $cancelled,
                $uid,
                $slot->originalDate,
                $slot->status === Slot::MOVED,
            );
        }

        $notices = [];
        if ($activeInWindow && !$masterFields->cancelled) {
            $notices = $masterFields->notices($this->settings->sourceId, $uid);
            foreach ($slots as $slot) {
                if ($slot->fields !== null && $slot->fields !== $masterFields && $slot->status !== Slot::CANCELLED) {
                    foreach ($slot->fields->notices($this->settings->sourceId, $uid) as $n) {
                        if ($n->code !== Notice::CODE_NO_CATEGORY) {
                            $notices[] = $n;
                        }
                    }
                }
            }
        }

        return ['occurrences' => $occurrences, 'series' => $series, 'notices' => self::dedupe($notices)];
    }

    private function changedSlot(VEvent $event, string $date, DateTimeImmutable $originalStart, DateTimeImmutable $originalEnd, DateInterval $length, EntryFields $masterFields, bool $extra): Slot
    {
        $fields = EntryFields::fromComponent($event, $this->settings, $this->mapper);
        $times = $this->times($event, $length);
        $actualStart = $times[0] ?? $originalStart;
        $actualEnd = $times[1] ?? $actualStart->add($length);
        if ($fields->cancelled || $masterFields->cancelled) {
            $status = Slot::CANCELLED;
        } elseif ($actualStart != $originalStart || $actualEnd != $originalEnd || $fields->room !== $masterFields->room) {
            $status = Slot::MOVED;
        } else {
            $status = Slot::CHANGED;
        }

        return new Slot($date, $originalStart, $originalEnd, $status, $actualStart, $actualEnd, $fields, $extra);
    }

    /**
     * @return array{0: array<int, DateTimeImmutable>, 1: bool, 2: array<string, mixed>} dates keyed by timestamp, open-ended, RRULE parts
     */
    private function pattern(VEvent $master, DateTimeImmutable $start, DateTimeImmutable $horizon): array
    {
        $dates = [$start->getTimestamp() => $start];
        $parts = [];
        $openEnded = false;
        if (isset($master->RRULE)) {
            $parts = array_change_key_case($master->RRULE->getParts(), CASE_UPPER);
            try {
                $it = new RRuleIterator($master->RRULE->getValue(), $start);
                $openEnded = $it->isInfinite();
                $n = 0;
                while ($it->valid()) {
                    $current = $it->current();
                    if (!$current instanceof DateTimeInterface) {
                        break;
                    }
                    $dt = DateTimeImmutable::createFromInterface($current)->setTimezone($this->tz);
                    if ($openEnded && $dt >= $horizon) {
                        break;
                    }
                    if (++$n > self::MAX_SLOTS) {
                        break;
                    }
                    $dates[$dt->getTimestamp()] = $dt;
                    $it->next();
                }
            } catch (Throwable) {
                // An unreadable rule leaves the first date only.
            }
        }
        foreach ($master->select('RDATE') as $property) {
            foreach ($this->dateTimes($property) as $dt) {
                $dates[$dt->getTimestamp()] = $dt;
            }
        }
        ksort($dates);

        return [$dates, $openEnded, $parts];
    }

    /**
     * @param array<string, mixed> $parts
     * @return list<int>
     */
    private function weekdays(array $parts, DateTimeImmutable $start): array
    {
        $codes = array_flip(GermanDate::CODES);
        $byDay = $parts['BYDAY'] ?? null;
        $days = [];
        foreach ((array) $byDay as $value) {
            $code = strtoupper(substr((string) $value, -2));
            if (isset($codes[$code])) {
                $days[] = $codes[$code];
            }
        }
        if ($days === []) {
            $days[] = (int) $start->format('N');
        }
        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /**
     * Start, exclusive end and all-day flag of a component, in Europe/Zurich.
     *
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: bool}|null
     */
    private function times(VEvent $event, ?DateInterval $defaultLength = null): ?array
    {
        if (!isset($event->DTSTART)) {
            return null;
        }
        $startProperty = $event->DTSTART;
        $start = $this->dateTime($startProperty);
        if ($start === null) {
            return null;
        }
        $allDay = $startProperty instanceof Property\ICalendar\DateTime && !$startProperty->hasTime();
        $end = null;
        if (isset($event->DTEND)) {
            $end = $this->dateTime($event->DTEND);
        } elseif (isset($event->DURATION)) {
            try {
                $end = $start->add($event->DURATION->getDateInterval());
            } catch (Throwable) {
                $end = null;
            }
        }
        if ($end === null || $end < $start) {
            $end = $defaultLength !== null ? $start->add($defaultLength) : ($allDay ? $start->modify('+1 day') : $start);
        }

        return [$start, $end, $allDay];
    }

    private function dateTime(?Property $property): ?DateTimeImmutable
    {
        if (!$property instanceof Property\ICalendar\DateTime) {
            return null;
        }
        try {
            return DateTimeImmutable::createFromInterface($property->getDateTime($this->tz))->setTimezone($this->tz);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<DateTimeImmutable> */
    private function dateTimes(Property $property): array
    {
        if (!$property instanceof Property\ICalendar\DateTime) {
            return [];
        }
        try {
            return array_map(
                fn (DateTimeInterface $d) => DateTimeImmutable::createFromInterface($d)->setTimezone($this->tz),
                $property->getDateTimes($this->tz)
            );
        } catch (Throwable) {
            return [];
        }
    }

    private function occurrence(
        string $uid,
        string $key,
        EntryFields $fields,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        bool $allDay,
        bool $cancelled,
        ?string $seriesUid,
        ?string $originalDate,
        bool $moved,
    ): Occurrence {
        return new Occurrence(
            $this->settings->sourceId,
            $uid,
            $key,
            $fields->title,
            $start,
            $end,
            $allDay,
            $fields->room,
            $fields->location,
            $fields->description,
            $fields->descriptionHtml,
            $fields->categories,
            $fields->tags,
            $cancelled,
            $seriesUid,
            $originalDate,
            $moved,
        );
    }

    /**
     * @param list<Notice> $notices
     * @return list<Notice>
     */
    private static function dedupe(array $notices): array
    {
        $out = [];
        foreach ($notices as $notice) {
            $out[$notice->dedupeKey()] ??= $notice;
        }

        return array_values($out);
    }
}

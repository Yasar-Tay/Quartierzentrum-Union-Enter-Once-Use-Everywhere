<?php

declare(strict_types=1);

namespace EnterOnce\Core;

use DateTimeImmutable;

/**
 * Entry point of the WordPress-free core. One instance per source and run.
 *
 *     $core    = new Core($settingsArray, 'union');
 *     $result  = $core->read($icsText, $now, $window, $lastRunHadEvents); // throws IntegrityError (R-03)
 *     $targets = $core->targets($result);
 *     $plan    = $core->diff($targets, $siteRecords);                     // SyncPlan
 *     $feed    = $core->feed($result->occurrences);                       // public feed items
 *
 * Same input → same output. No clock, no network, no WordPress.
 */
final class Core
{
    public readonly Settings $settings;

    /** @param array<string, mixed> $settings the `enteronce_settings` array (section 6.6) */
    public function __construct(array $settings, string $sourceId)
    {
        $this->settings = new Settings($settings, $sourceId);
    }

    /**
     * Parse, screen (R-01, R-12), expand and normalise.
     *
     * @throws IntegrityError when the text is empty, not a calendar, truncated, unreadable,
     *                        or has no events although the last run had some
     */
    public function read(string $icsText, DateTimeImmutable $now, Window $window, bool $lastRunHadEvents = false): CalendarResult
    {
        $now = $now->setTimezone(Settings::timezone());
        $calendar = IcsReader::read($icsText, $lastRunHadEvents);
        $eventCount = count($calendar->select('VEVENT'));
        $screened = (new Screener($this->settings))->screen($calendar);
        $expander = new Expander($this->settings);

        $occurrences = [];
        $series = [];
        $notices = [];
        foreach ($screened['groups'] as $uid => $group) {
            $out = $expander->expand((string) $uid, $group, $now, $window);
            array_push($occurrences, ...$out['occurrences']);
            if ($out['series'] !== null) {
                $series[] = $out['series'];
            }
            array_push($notices, ...$out['notices']);
        }
        usort($occurrences, static fn (Occurrence $a, Occurrence $b) => [$a->start->getTimestamp(), $a->syncKey] <=> [$b->start->getTimestamp(), $b->syncKey]);

        return new CalendarResult($this->settings->sourceId, $now, $window, $occurrences, $series, $notices, $screened['dropped'], $eventCount);
    }

    public function targets(CalendarResult $result): TargetSet
    {
        return (new TargetBuilder($this->settings))->build($result);
    }

    /**
     * @param list<SiteRecord> $siteRecords
     * @param bool $releaseHeld true when an administrator pressed «Trotzdem anwenden» (R-13)
     */
    public function diff(TargetSet $targets, array $siteRecords, bool $releaseHeld = false): SyncPlan
    {
        return (new Differ($this->settings))->diff($targets, $siteRecords, $releaseHeld);
    }

    /**
     * @param list<Occurrence> $occurrences usually CalendarResult::$occurrences or the stored list
     * @return list<array<string, mixed>>
     */
    public function feed(array $occurrences, ?Window $range = null): array
    {
        return Feed::build($occurrences, $this->settings, $range);
    }
}

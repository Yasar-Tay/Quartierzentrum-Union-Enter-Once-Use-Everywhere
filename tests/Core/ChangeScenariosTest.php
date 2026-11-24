<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Core;

use EnterOnce\Core\Action;
use EnterOnce\Core\IntegrityError;
use EnterOnce\Core\SiteRecord;
use EnterOnce\Core\SyncPlan;
use EnterOnce\Core\TargetRecord;
use EnterOnce\Core\Window;

/**
 * Plan section 8.3: change scenarios. Base: the sample calendar, synced once at
 * 19 Nov 2026 00:00 (window 19 Nov – 31 Mar), then changed and synced again 15 minutes later.
 */
final class ChangeScenariosTest extends CoreTestCase
{
    private const NOW = '2026-11-19 00:00';
    private const LATER = '2026-11-19 00:15';
    private const LATER2 = '2026-11-19 00:30';
    private const FROM = '2026-11-19';
    private const TO = '2027-03-31';

    /** @return list<SiteRecord> */
    private function baseline(array $settings = []): array
    {
        [, $site] = self::sync(self::fixture(self::SAMPLE), self::NOW, self::FROM, self::TO, [], $settings);

        return $site;
    }

    /** @return array{0: SyncPlan, 1: list<SiteRecord>} */
    private function next(string $ics, array $site, string $now = self::LATER, array $settings = [], bool $release = false): array
    {
        return self::sync($ics, $now, self::FROM, self::TO, $site, $settings, $release);
    }

    /** @return array<string, int> counts of changes only */
    private static function changeCounts(SyncPlan $plan): array
    {
        $c = $plan->counts();
        unset($c[Action::KEEP]);

        return $c;
    }

    public function testSameFileAgain(): void
    {
        [$plan] = $this->next(self::fixture(self::SAMPLE), $this->baseline());
        self::assertSame([], $plan->changes());
    }

    public function testSingleEventChangesItsTime(): void
    {
        $ics = self::inEvent(self::fixture(self::SAMPLE), 'union-mittwochsbar-1125@example', 'T190000', 'T200000');
        [$plan] = $this->next($ics, $this->baseline());
        self::assertSame([Action::CREATE => 0, Action::UPDATE => 1, Action::UNPUBLISH => 0, Action::HOLD => 0], self::changeCounts($plan));
        self::assertSame('union:union-mittwochsbar-1125@example', $plan->ofType(Action::UPDATE)[0]->key);
    }

    public function testWholeSeriesChangesItsTime(): void
    {
        $ics = self::inEvent(self::fixture(self::SAMPLE), 'union-seniorensport@example', 'T090000', 'T093000');
        $ics = self::inEvent($ics, 'union-seniorensport@example', 'DTEND;TZID=W. Europe Standard Time:20261120T100000', 'DTEND;TZID=W. Europe Standard Time:20261120T103000');
        [$plan] = $this->next($ics, $this->baseline());
        self::assertSame([Action::CREATE => 0, Action::UPDATE => 1, Action::UNPUBLISH => 0, Action::HOLD => 0], self::changeCounts($plan), 'weekly offer');

        $perDate = ['weekly_mode' => 'per_date'];
        [$plan] = $this->next($ics, $this->baseline($perDate), self::LATER, $perDate);
        $c = self::changeCounts($plan);
        self::assertSame(0, $c[Action::CREATE]);
        self::assertSame(0, $c[Action::UNPUBLISH]);
        self::assertSame(19, $c[Action::UPDATE], 'per_date: every date updates, none is created');
    }

    public function testOneDateOfASeriesMovedInPerDateMode(): void
    {
        $perDate = ['weekly_mode' => 'per_date'];
        $ics = self::inEvent(self::fixture(self::SAMPLE), 'union-yoga@example', 'DTSTART;TZID=W. Europe Standard Time:20261207T100000', 'DTSTART;TZID=W. Europe Standard Time:20261207T113000');
        $ics = str_replace('DTEND;TZID=W. Europe Standard Time:20261207T110000', 'DTEND;TZID=W. Europe Standard Time:20261207T123000', $ics);
        [$plan] = $this->next($ics, $this->baseline($perDate), self::LATER, $perDate);
        self::assertSame([Action::CREATE => 0, Action::UPDATE => 1, Action::UNPUBLISH => 0, Action::HOLD => 0], self::changeCounts($plan));
        self::assertSame('union:union-yoga@example:2026-12-07', $plan->ofType(Action::UPDATE)[0]->key);
    }

    public function testOneDateOfAWeeklyOfferMoved(): void
    {
        $site = $this->baseline();
        $moved = "BEGIN:VEVENT\r\nUID:union-seniorensport@example\r\nRECURRENCE-ID;TZID=W. Europe Standard Time:20261127T090000\r\n"
            . "SUMMARY:Seniorensport\r\nDTSTART;TZID=W. Europe Standard Time:20261127T100000\r\nDTEND;TZID=W. Europe Standard Time:20261127T110000\r\n"
            . "LOCATION:Bühnensaal\\, Quartierzentrum Union\r\nDESCRIPTION:Bewegung in der Gruppe.\\n\\n[event-type]\\nname=Bewegung\\n[/event-type]\r\n"
            . "CLASS:PUBLIC\r\nEND:VEVENT\r\n";
        $ics = self::insertBeforeEnd(self::fixture(self::SAMPLE), $moved);

        // While the 20 November date is next, nothing changes for the weekly offer.
        [$plan] = $this->next($ics, $site, self::LATER);
        self::assertSame([], array_filter($plan->changes(), static fn (Action $a) => $a->kind === TargetRecord::WEEKLY_OFFER));

        // After the 20 November date has passed, 27 November is next: the record gets the note (R-24).
        [$plan] = $this->next($ics, $site, '2026-11-20 10:00');
        $updates = array_values(array_filter($plan->ofType(Action::UPDATE), static fn (Action $a) => $a->key === 'union:union-seniorensport@example:FR'));
        self::assertCount(1, $updates);
        self::assertSame('Am 27. November ausnahmsweise 10:00–11:00 Uhr im Raum Bühnensaal', $updates[0]->target->fields['note']);
        self::assertSame('09:00', $updates[0]->target->fields['time_start']);
    }

    public function testSeriesMovesToAnotherWeekday(): void
    {
        $ics = self::inEvent(self::fixture(self::SAMPLE), 'union-seniorensport@example', 'BYDAY=FR', 'BYDAY=SA');
        $ics = self::inEvent($ics, 'union-seniorensport@example', ':20261120T', ':20261121T');
        [$first, $site] = $this->next($ics, $this->baseline());
        self::assertCount(1, $first->ofType(Action::CREATE));
        self::assertSame('union:union-seniorensport@example:SA', $first->ofType(Action::CREATE)[0]->key);
        self::assertSame([], $first->ofType(Action::UNPUBLISH), 'first run: grace (R-05)');
        [$second] = $this->next($ics, $site, self::LATER2);
        $un = $second->ofType(Action::UNPUBLISH);
        self::assertCount(1, $un);
        self::assertSame('union:union-seniorensport@example:FR', $un[0]->key);
        self::assertSame([], $second->ofType(Action::CREATE));
    }

    public function testEventDeletedFromTheFile(): void
    {
        $ics = self::withoutEvent(self::fixture(self::SAMPLE), 'union-brunch-1129@example');
        [$first, $site] = $this->next($ics, $this->baseline());
        self::assertSame([], $first->changes(), 'first run: none');
        [$second] = $this->next($ics, $site, self::LATER2);
        self::assertSame([Action::CREATE => 0, Action::UPDATE => 0, Action::UNPUBLISH => 1, Action::HOLD => 0], self::changeCounts($second));
        self::assertSame('union:union-brunch-1129@example', $second->ofType(Action::UNPUBLISH)[0]->key);
        self::assertSame('missing', $second->ofType(Action::UNPUBLISH)[0]->reason);
    }

    public function testSixEventsDeletedAtOnceAreHeld(): void
    {
        $ics = self::fixture(self::SAMPLE);
        foreach (['union-mittwochsbar-1125@example', 'union-brunch-1129@example', 'union-theater-1127@example', 'union-repaircafe-1205@example', 'union-seniorensport@example', 'union-kinderpiazza@example'] as $uid) {
            $ics = self::withoutEvent($ics, $uid);
        }
        [$first, $site] = $this->next($ics, $this->baseline());
        self::assertSame([], $first->changes());
        [$second, $siteAfter] = $this->next($ics, $site, self::LATER2);
        self::assertSame([], $second->ofType(Action::UNPUBLISH), 'no unpublish');
        self::assertTrue($second->held, 'run held (R-13); the shell sends the e-mail');
        self::assertCount(6, $second->ofType(Action::HOLD));
        foreach ($siteAfter as $r) {
            self::assertSame(TargetRecord::PUBLISH, $r->status);
        }
        // «Trotzdem anwenden»
        [$released] = $this->next($ics, $siteAfter, '2026-11-19 00:35', [], true);
        self::assertCount(6, $released->ofType(Action::UNPUBLISH));
        self::assertFalse($released->held);
    }

    public function testEventIsCancelled(): void
    {
        $ics = self::inEvent(self::fixture(self::SAMPLE), 'union-mittwochsbar-1125@example', 'STATUS:CONFIRMED', 'STATUS:CANCELLED');
        [$plan] = $this->next($ics, $this->baseline());
        self::assertSame([Action::CREATE => 0, Action::UPDATE => 0, Action::UNPUBLISH => 1, Action::HOLD => 0], self::changeCounts($plan), 'at once (A-06)');
        self::assertSame('cancelled', $plan->ofType(Action::UNPUBLISH)[0]->reason);
    }

    public function testUnpublishedEventReturns(): void
    {
        $without = self::withoutEvent(self::fixture(self::SAMPLE), 'union-brunch-1129@example');
        [, $site] = $this->next($without, $this->baseline());
        [, $site] = $this->next($without, $site, self::LATER2);
        $brunch = array_values(array_filter($site, static fn ($r) => $r->key === 'union:union-brunch-1129@example'))[0];
        self::assertSame(TargetRecord::DRAFT, $brunch->status);

        [$plan] = $this->next(self::fixture(self::SAMPLE), $site, '2026-11-19 00:45');
        self::assertSame([Action::CREATE => 0, Action::UPDATE => 1, Action::UNPUBLISH => 0, Action::HOLD => 0], self::changeCounts($plan));
        $u = $plan->ofType(Action::UPDATE)[0];
        self::assertSame('republish', $u->reason);
        self::assertSame('union:union-brunch-1129@example', $u->siteKey, 'the same record');
        self::assertSame(TargetRecord::PUBLISH, $u->status);
        self::assertSame(0, $u->missingRuns);
    }

    public function testEndedWeeklySeriesDraftIsReusedByNewSeries(): void
    {
        // Kinderpiazza ends on 8 February; the run of 9 February sets it to draft (R-23).
        [, $site] = self::sync(self::fixture(self::SAMPLE), '2027-02-01 12:00', '2027-02-01', '2027-04-30');
        [$ended, $site] = self::sync(self::fixture(self::SAMPLE), '2027-02-09 08:00', '2027-02-09', '2027-04-30', $site);
        self::assertSame('union:union-kinderpiazza@example:MO', $ended->ofType(Action::UNPUBLISH)[0]->key);

        $newSeries = "BEGIN:VEVENT\r\nUID:union-kinderpiazza-fruehling@example\r\nSUMMARY:Kinderpiazza\r\n"
            . "DTSTART;TZID=W. Europe Standard Time:20270222T140000\r\nDTEND;TZID=W. Europe Standard Time:20270222T170000\r\n"
            . "RRULE:FREQ=WEEKLY;COUNT=10;BYDAY=MO\r\nLOCATION:Grosser Saal\\, Quartierzentrum Union\r\n"
            . "DESCRIPTION:Neue Saison.\\n\\n[event-type]\\nname=Offener Treffpunkt\\n[/event-type]\r\nCLASS:PUBLIC\r\nEND:VEVENT\r\n";
        $ics = self::insertBeforeEnd(self::fixture(self::SAMPLE), $newSeries);
        [$plan] = self::sync($ics, '2027-02-15 08:00', '2027-02-15', '2027-05-15', $site);
        $kinder = array_values(array_filter($plan->changes(), static fn (Action $a) => str_contains($a->key, 'kinderpiazza')));
        self::assertCount(1, $kinder);
        self::assertSame(Action::UPDATE, $kinder[0]->type);
        self::assertSame('reuse_draft', $kinder[0]->reason);
        self::assertSame('union:union-kinderpiazza-fruehling@example:MO', $kinder[0]->key);
        self::assertSame('union:union-kinderpiazza@example:MO', $kinder[0]->siteKey);
        self::assertSame([], $plan->ofType(Action::CREATE), 'no new record');
    }

    public function testWeeklySeriesStartingInSixWeeksIsCreated14DaysBefore(): void
    {
        // The live calendar's series start on 4–8 January 2027.
        $ics = self::fixture(self::LIVE);
        $keyMo = 'union:040000008200E00074C5B7101A82E0080000000050756CA84277DD0100000000000000001000000088CC4D047EC66C4CA4C9D244DCA87503:MO';
        [$early] = self::sync($ics, '2026-11-23 00:00', '2026-11-23', '2027-02-23');
        self::assertSame([], array_filter($early->actions, static fn (Action $a) => $a->key === $keyMo), 'no record yet');
        [$justBefore] = self::sync($ics, '2026-12-21 08:59', '2026-12-21', '2027-03-21');
        self::assertSame([], array_filter($justBefore->actions, static fn (Action $a) => $a->key === $keyMo));
        [$due] = self::sync($ics, '2026-12-21 09:00', '2026-12-21', '2027-03-21');
        $create = array_values(array_filter($due->ofType(Action::CREATE), static fn (Action $a) => $a->key === $keyMo));
        self::assertCount(1, $create, 'created 14 days before the first date (R-26)');
    }

    public function testWeeklySeriesWithThreeDatesBecomesDatedEvents(): void
    {
        $t = self::targets(self::fixture('recurrence-patterns.ics'), '2027-01-04 00:00', '2027-01-04', '2027-03-31');
        $short = array_values(array_filter($t->targets, static fn (TargetRecord $x) => $x->uid === 'fixture-three-dates@example.org'));
        self::assertCount(3, $short);
        foreach ($short as $x) {
            self::assertSame(TargetRecord::DATED_EVENT, $x->kind);
        }
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function brokenTexts(): iterable
    {
        $sample = file_get_contents(dirname(__DIR__) . '/fixtures/union-beispielkalender.ics');
        yield 'empty' => ['', IntegrityError::REASON_EMPTY_TEXT];
        yield 'whitespace' => ["  \r\n ", IntegrityError::REASON_EMPTY_TEXT];
        yield 'html error page' => ['<html><body>502 Bad Gateway</body></html>', IntegrityError::REASON_NOT_A_CALENDAR];
        yield 'truncated' => [substr($sample, 0, (int) (strlen($sample) * 0.6)), IntegrityError::REASON_TRUNCATED];
        yield 'broken inside' => ["BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:x\r\nEND:VCALENDAR\r\n", IntegrityError::REASON_PARSE_ERROR];
    }

    /** @dataProvider brokenTexts */
    public function testEmptyBrokenOrTruncatedTextChangesNothing(string $text, string $reason): void
    {
        $site = $this->baseline();
        $core = self::core();
        try {
            $core->read($text, self::at(self::LATER), Window::fromDates(self::FROM, self::TO), true);
            self::fail('IntegrityError expected');
        } catch (IntegrityError $e) {
            self::assertSame($reason, $e->reason);
            self::assertNotSame('', $e->germanMessage());
        }
        self::assertCount(8, $site, 'nothing was applied');
    }

    public function testFeedThatHadEventsIsNowEmpty(): void
    {
        $empty = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nEND:VCALENDAR\r\n";
        $this->expectException(IntegrityError::class);
        self::core()->read($empty, self::at(self::LATER), Window::fromDates(self::FROM, self::TO), true);
    }

    public function testEmptyCalendarIsFineOnAFirstRun(): void
    {
        $empty = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nEND:VCALENDAR\r\n";
        $r = self::core()->read($empty, self::at(self::LATER), Window::fromDates(self::FROM, self::TO), false);
        self::assertSame([], $r->occurrences);
        self::assertSame(0, $r->eventCount);
    }

    public function testMappingTableChangeUpdatesAffectedRecords(): void
    {
        $site = $this->baseline();
        $settings = ['category_map' => ['Bewegung' => ['Sport & Spiel']] + \EnterOnce\Core\Settings::defaults()['category_map']];
        [$plan] = $this->next(self::fixture(self::SAMPLE), $site, self::LATER, $settings);
        $updated = array_map(static fn (Action $a) => $a->key, $plan->ofType(Action::UPDATE));
        sort($updated);
        self::assertSame([
            'union:union-kinderpiazza@example:MO',
            'union:union-seniorensport@example:FR',
            'union:union-yoga@example:MO',
        ], $updated);
        self::assertCount(3, $plan->changes());
    }

    public function testTwoCentresNeverTouchEachOther(): void
    {
        $settings = ['sources' => [['id' => 'union', 'location_term' => 52], ['id' => 'other', 'location_term' => 60]]];
        $site = $this->baseline($settings);
        $foreign = [
            new SiteRecord('other:abc@example', TargetRecord::DATED_EVENT, 'v1:x', 'publish', 1, 'other'),
            new SiteRecord('other:def@example:MO', TargetRecord::WEEKLY_OFFER, 'v1:y', 'publish', 5, 'other'),
            // Same key as a Union record, but not managed (hand-made) – must be ignored, too.
            new SiteRecord('union:union-brunch-1129@example', TargetRecord::DATED_EVENT, null, 'publish', 0, 'union', false),
        ];
        [$plan] = $this->next(self::fixture(self::SAMPLE), array_merge($site, $foreign), self::LATER, $settings);
        self::assertSame([], $plan->changes());
        foreach ($plan->actions as $a) {
            self::assertStringStartsWith('union:', $a->key);
        }
        self::assertCount(8, $plan->actions, 'only the 8 managed Union records');

        // An empty Union calendar does not unpublish the other centre's records either.
        $without = self::fixture(self::SAMPLE);
        foreach (['union-mittwochsbar-1125@example', 'union-brunch-1129@example'] as $uid) {
            $without = self::withoutEvent($without, $uid);
        }
        [, $s1] = $this->next($without, array_merge($site, $foreign), self::LATER, $settings);
        [$p2] = $this->next($without, $s1, self::LATER2, $settings);
        foreach ($p2->changes() as $a) {
            self::assertStringStartsWith('union:', (string) $a->siteKey);
        }
        $other = new \EnterOnce\Core\Core($settings, 'other');
        self::assertSame(60, $other->settings->locationTerm());
    }

    public function testDryRunFlag(): void
    {
        [$plan] = $this->next(self::fixture(self::SAMPLE), []);
        self::assertTrue($plan->dryRun, 'mapping unverified (R-11)');
        self::assertCount(8, $plan->ofType(Action::CREATE), 'all actions are computed (R-31)');
        $live = ['field_map_verified' => true, 'sources' => [['id' => 'union', 'dry_run' => false]]];
        [$plan] = $this->next(self::fixture(self::SAMPLE), [], self::LATER, $live);
        self::assertFalse($plan->dryRun);
        $dry = ['field_map_verified' => true, 'sources' => [['id' => 'union', 'dry_run' => true]]];
        [$plan] = $this->next(self::fixture(self::SAMPLE), [], self::LATER, $dry);
        self::assertTrue($plan->dryRun);
    }

    public function testPastRecordsAreNeverChanged(): void
    {
        $site = $this->baseline();
        // After the MittwochsBAR (25 Nov, until 23:00), its title changes in the calendar.
        $ics = self::inEvent(self::fixture(self::SAMPLE), 'union-mittwochsbar-1125@example', 'SUMMARY:MittwochsBAR im Union', 'SUMMARY:MittwochsBAR');
        [$plan] = self::sync($ics, '2026-11-26 08:00', '2026-11-19', self::TO, $site);
        $bar = array_values(array_filter($plan->actions, static fn (Action $a) => $a->key === 'union:union-mittwochsbar-1125@example'))[0];
        self::assertSame(Action::KEEP, $bar->type);
        self::assertSame('past', $bar->reason);
        // Gone from the window entirely: still not unpublished.
        [$plan] = self::sync(self::withoutEvent(self::fixture(self::SAMPLE), 'union-mittwochsbar-1125@example'), '2026-11-26 08:00', '2026-11-26', self::TO, $site);
        [$plan] = self::sync(self::withoutEvent(self::fixture(self::SAMPLE), 'union-mittwochsbar-1125@example'), '2026-11-26 08:15', '2026-11-26', self::TO, self::apply($plan, $site));
        $bar = array_values(array_filter($plan->actions, static fn (Action $a) => $a->key === 'union:union-mittwochsbar-1125@example'))[0];
        self::assertSame(Action::KEEP, $bar->type);
    }

    public function testMissingOutsideTheWindowIsNotUnpublished(): void
    {
        $far = new SiteRecord('union:far-away@example', TargetRecord::DATED_EVENT, 'v1:z', 'publish', 3, 'union', true, false, [], self::at('2027-06-01 10:00'), self::at('2027-06-01 12:00'));
        [$plan] = $this->next(self::fixture(self::SAMPLE), array_merge($this->baseline(), [$far]));
        $a = array_values(array_filter($plan->actions, static fn (Action $a) => $a->key === 'union:far-away@example'))[0];
        self::assertSame(Action::KEEP, $a->type);
        self::assertSame('outside_window', $a->reason);
    }

    public function testKeepTextRecordsIgnoreTextChanges(): void
    {
        $site = array_map(static function (SiteRecord $r) {
            if ($r->key !== 'union:union-yoga@example:MO') {
                return $r;
            }
            $t = self::targets(self::fixture(self::SAMPLE), self::NOW, self::FROM, self::TO)->byKey($r->key);

            return SiteRecord::fromTarget($t, true);
        }, $this->baseline());
        $ics = self::inEvent(self::fixture(self::SAMPLE), 'union-yoga@example', 'Sanftes Yoga für alle Niveaus. Matten', 'Sanftes Yoga. Matten');
        [$plan] = $this->next($ics, $site);
        self::assertSame([], $plan->changes(), 'adopted record keeps its text (A-14)');
        $ics = self::inEvent($ics, 'union-yoga@example', 'T090000', 'T091500');
        [$plan] = $this->next($ics, $site);
        self::assertCount(1, $plan->ofType(Action::UPDATE), 'time changes still update');
    }
}

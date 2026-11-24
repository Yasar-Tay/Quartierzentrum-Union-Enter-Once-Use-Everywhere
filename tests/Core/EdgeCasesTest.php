<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Core;

use EnterOnce\Core\Action;
use EnterOnce\Core\Notice;
use EnterOnce\Core\TargetRecord;
use EnterOnce\Core\Window;

/** Plan section 8.4: cases covered by hand-made fixtures. Now 4 Jan 2027 00:00, window 4 Jan – 31 Mar 2027. */
final class EdgeCasesTest extends CoreTestCase
{
    private const NOW = '2027-01-04 00:00';
    private const FROM = '2027-01-04';
    private const TO = '2027-03-31';

    public function testEntryWithoutClassLineIsKept(): void
    {
        $r = self::read(self::fixture('no-class-line.ics'), self::NOW, self::FROM, self::TO);
        $titles = array_map(static fn ($o) => $o->title, $r->occurrences);
        self::assertSame(['Spielabend ohne CLASS-Zeile', 'Flohmarkt'], $titles, 'no CLASS → public; CLASS:public is PUBLIC');
        self::assertSame(2, $r->droppedInternal, 'CLASS:CONFIDENTIAL and CATEGORIES containing «intern»');
    }

    public function testAttendeeLinesReachNoOutput(): void
    {
        $core = self::core();
        $r = $core->read(self::fixture('attendees.ics'), self::at(self::NOW), Window::fromDates(self::FROM, self::TO));
        $targets = $core->targets($r);
        $plan = $core->diff($targets, []);
        $all = json_encode([
            array_map(static fn ($o) => $o->toArray(), $r->occurrences),
            array_map(static fn ($s) => $s->toArray(), $r->series),
            array_map(static fn ($n) => $n->toArray(), $r->notices),
            array_map(static fn ($t) => $t->toArray(), $targets->targets),
            $plan->toArray(),
            $core->feed($r->occurrences),
        ], JSON_UNESCAPED_UNICODE);
        self::assertNotFalse($all);
        foreach (['Erika', 'Muster', 'Max Beispiel', 'erika.muster', 'max.beispiel', 'programm-kalender', 'mailto', 'Erinnerung'] as $personal) {
            self::assertStringNotContainsString($personal, $all, "R-12: «{$personal}» leaked");
        }
        self::assertCount(7, $r->activeOccurrences(), '1 single + 6 series dates');
    }

    public function testSingleDateOfASeriesCancelled(): void
    {
        $core = self::core();
        $r = $core->read(self::fixture('cancelled-dates.ics'), self::at(self::NOW), Window::fromDates(self::FROM, self::TO));
        $tanz = self::byTitle($r->occurrences, 'Tanznachmittag');
        self::assertCount(13, $tanz, 'Tuesdays 5 Jan – 30 Mar');
        $cancelled = array_values(array_map(static fn ($o) => $o->originalDate, array_filter($tanz, static fn ($o) => $o->cancelled)));
        self::assertSame(['2027-01-19', '2027-02-02'], $cancelled, 'by STATUS and by title prefix');
        foreach ($tanz as $o) {
            self::assertSame('Tanznachmittag', $o->title, 'prefix removed');
        }
        $series = $r->series[0]->toArray();
        self::assertSame(['2027-01-19', '2027-02-02'], $series['cancelled_dates']);
        self::assertSame([], $r->notices, 'no notice for a cancelled event, none for the cancelled dates');

        // Weekly offer carries «abgesagt» while the cancelled date is next (R-22).
        $flag = static function (string $now): array {
            $t = self::targets(self::fixture('cancelled-dates.ics'), $now, self::FROM, self::TO)->byKey('union:fixture-tanz@example.org:TU');

            return [$t->fields['abgesagt'], $t->fields['ferienpause'], $t->nextDate];
        };
        self::assertSame([false, false, '2027-01-12'], $flag('2027-01-05 16:00'));
        self::assertSame([true, false, '2027-01-19'], $flag('2027-01-12 16:00'));
        self::assertSame([false, false, '2027-01-26'], $flag('2027-01-19 16:00'));

        // Feed: cancelled dates hidden by default, flagged with cancel_mode badge.
        self::assertCount(11, $core->feed(self::byTitle($r->occurrences, 'Tanznachmittag')));
        $badge = self::core(['cancel_mode' => 'badge'])->feed(self::byTitle($r->occurrences, 'Tanznachmittag'));
        self::assertCount(13, $badge);
        self::assertCount(2, array_filter($badge, static fn ($i) => $i['cancelled']));

        // per_date: the cancelled date is unpublished if online, not created otherwise.
        $t = self::targets(self::fixture('cancelled-dates.ics'), self::NOW, self::FROM, self::TO, ['weekly_mode' => 'per_date']);
        self::assertSame(TargetRecord::DRAFT, $t->byKey('union:fixture-tanz@example.org:2027-01-19')->status);
        self::assertSame(TargetRecord::PUBLISH, $t->byKey('union:fixture-tanz@example.org:2027-01-12')->status);
    }

    public function testEventCancelledOnlyByTitlePrefix(): void
    {
        $r = self::read(self::fixture('cancelled-dates.ics'), self::NOW, self::FROM, self::TO);
        $vortrag = self::byTitle($r->occurrences, 'Vortrag Stadtgeschichte');
        self::assertCount(1, $vortrag, '«cancelled:» is matched case-insensitively and removed');
        self::assertTrue($vortrag[0]->cancelled);
        $warnings = array_filter($r->notices, static fn (Notice $n) => $n->value === 'Kulturx');
        self::assertSame([], $warnings, 'cancelled: no warning for its unknown category');
    }

    public function testSeriesSplitByThisAndAllFollowing(): void
    {
        $ics = self::fixture('series-split.ics');
        $r = self::read($ics, self::NOW, self::FROM, self::TO);
        $dates = self::byTitle($r->occurrences, 'Quartierchor');
        $days = array_map(static fn ($o) => $o->start->format('Y-m-d H:i'), $dates);
        self::assertSame('2027-01-05 19:00', $days[0]);
        self::assertSame('2027-01-12 19:00', $days[1], 'last date of the old series');
        self::assertSame('2027-01-19 19:30', $days[2], 'first date of the new series');
        self::assertCount(count(array_unique(array_map(static fn ($o) => $o->start->format('Y-m-d'), $dates))), $dates, 'no date twice');
        self::assertCount(13, $dates, 'every Tuesday 5 Jan – 30 Mar once');
        self::assertSame('union:fixture-chor-part1@example.org:2027-01-12', $dates[1]->syncKey, 'old dates keep their keys');
        self::assertSame('union:fixture-chor-part2@example.org:2027-01-19', $dates[2]->syncKey);

        // Weekly offers: the old one runs until 12 January, the new one appears 14 days before 19 January.
        $t = self::targets($ics, '2027-01-04 00:00', self::FROM, self::TO);
        self::assertNotNull($t->byKey('union:fixture-chor-part1@example.org:TU'));
        self::assertSame('union:fixture-chor-part2@example.org:TU', $t->pending[0]->key);
        $t = self::targets($ics, '2027-01-12 21:00', self::FROM, self::TO);
        self::assertSame(TargetRecord::DRAFT, $t->byKey('union:fixture-chor-part1@example.org:TU')->status);
        self::assertSame(TargetRecord::PUBLISH, $t->byKey('union:fixture-chor-part2@example.org:TU')->status);
        self::assertSame('19:30', $t->byKey('union:fixture-chor-part2@example.org:TU')->fields['time_start']);
    }

    public function testAllDayAndMultiDay(): void
    {
        $r = self::read(self::fixture('all-day-and-multi-day.ics'), self::NOW, self::FROM, self::TO);
        $fest = self::byTitle($r->occurrences, 'Quartierfest')[0];
        self::assertTrue($fest->allDay);
        self::assertSame('2027-02-13T00:00:00+01:00', $fest->start->format(DATE_ATOM));
        self::assertSame('2027-02-14T00:00:00+01:00', $fest->end->format(DATE_ATOM), 'exclusive end');
        self::assertSame(['Familie'], $fest->tags, 'synonym «Familien» → Familie, silently');
        self::assertSame([], $r->warnings());

        $t = self::targets(self::fixture('all-day-and-multi-day.ics'), self::NOW, self::FROM, self::TO);
        $festT = $t->byKey('union:fixture-allday-1@example.org');
        self::assertSame(['2027-02-13', '2027-02-13', null, null, true], [$festT->fields['date'], $festT->fields['end_date'], $festT->fields['time_start'], $festT->fields['time_end'], $festT->fields['all_day']]);
        $ausstellung = $t->byKey('union:fixture-multiday-allday@example.org');
        self::assertSame(['2027-02-19', '2027-02-21'], [$ausstellung->fields['date'], $ausstellung->fields['end_date']]);
        $wochenende = $t->byKey('union:fixture-multiday-timed@example.org');
        self::assertSame(['2027-03-05', '2027-03-07', '18:00', '16:00', false], [$wochenende->fields['date'], $wochenende->fields['end_date'], $wochenende->fields['time_start'], $wochenende->fields['time_end'], $wochenende->fields['all_day']]);

        $feed = self::core()->feed($r->occurrences);
        self::assertTrue($feed[0]['all_day']);
        self::assertNull($feed[0]['series_id']);
    }

    public function testFirstSaturdayOfTheMonth(): void
    {
        $t = self::targets(self::fixture('recurrence-patterns.ics'), self::NOW, self::FROM, self::TO);
        $repair = array_values(array_filter($t->targets, static fn ($x) => $x->uid === 'fixture-first-saturday@example.org'));
        self::assertSame(['2027-02-06', '2027-03-06'], array_map(static fn ($x) => $x->fields['date'], $repair), '2 January is before the window');
        foreach ($repair as $x) {
            self::assertSame(TargetRecord::DATED_EVENT, $x->kind, 'R-25');
        }
    }

    public function testEveryTwoWeeks(): void
    {
        $t = self::targets(self::fixture('recurrence-patterns.ics'), self::NOW, self::FROM, self::TO);
        $velo = array_values(array_filter($t->targets, static fn ($x) => $x->uid === 'fixture-biweekly@example.org'));
        self::assertSame(
            ['2027-01-06', '2027-01-20', '2027-02-03', '2027-02-17', '2027-03-03', '2027-03-17', '2027-03-31'],
            array_map(static fn ($x) => $x->fields['date'], $velo)
        );
        foreach ($velo as $x) {
            self::assertSame(TargetRecord::DATED_EVENT, $x->kind, 'R-25');
        }
    }

    public function testSeriesWithoutEndDate(): void
    {
        $r = self::read(self::fixture('recurrence-patterns.ics'), self::NOW, self::FROM, self::TO);
        $s = array_values(array_filter($r->series, static fn ($x) => $x->uid === 'fixture-open-ended@example.org'))[0]->toArray();
        self::assertTrue($s['open_ended']);
        self::assertNull($s['last_date']);
        self::assertNull($s['date_count']);
        self::assertCount(12, self::byTitle($r->occurrences, 'Morgenkaffee'), 'Fridays 8 Jan – 26 Mar inside the window only');

        $t = self::targets(self::fixture('recurrence-patterns.ics'), self::NOW, self::FROM, self::TO)->byKey('union:fixture-open-ended@example.org:FR');
        self::assertSame(TargetRecord::PUBLISH, $t->status);
        self::assertNull($t->fields['last_date']);
        self::assertNull($t->end);
    }

    public function testSeriesOnTwoWeekdays(): void
    {
        $t = self::targets(self::fixture('recurrence-patterns.ics'), self::NOW, self::FROM, self::TO);
        $tu = $t->byKey('union:fixture-two-weekdays@example.org:TU');
        $th = $t->byKey('union:fixture-two-weekdays@example.org:TH');
        self::assertSame(TargetRecord::WEEKLY_OFFER, $tu->kind);
        self::assertSame(TargetRecord::WEEKLY_OFFER, $th->kind);
        self::assertSame([2, '2027-01-05', '2027-03-23'], [$tu->fields['weekday'], $tu->fields['first_date'], $tu->fields['last_date']]);
        self::assertSame([4, '2027-01-07', '2027-03-25'], [$th->fields['weekday'], $th->fields['first_date'], $th->fields['last_date']]);
        self::assertSame(['Für Kinder'], $tu->fields['target_groups']);
    }

    public function testTwoDatesOnOneDayIsAWarningAndNotSynced(): void
    {
        $r = self::read(self::fixture('recurrence-patterns.ics'), self::NOW, self::FROM, self::TO);
        $w = array_values(array_filter($r->warnings(), static fn (Notice $n) => $n->code === Notice::CODE_TWO_DATES_ONE_DAY));
        self::assertCount(1, $w, 'once per series');
        self::assertSame('Sprechstunde', $w[0]->title);
        self::assertSame([], self::byTitle($r->occurrences, 'Sprechstunde'));
        $t = self::targets(self::fixture('recurrence-patterns.ics'), self::NOW, self::FROM, self::TO);
        self::assertSame([], array_filter($t->targets, static fn ($x) => $x->uid === 'fixture-two-per-day@example.org'));
    }

    public function testNoticesAreCountedOncePerSeries(): void
    {
        $ics = str_replace('name=Kreativität', 'name=Kreativitat', self::fixture('attendees.ics'));
        $r = self::read($ics, self::NOW, self::FROM, self::TO);
        $w = array_values(array_filter($r->warnings(), static fn (Notice $n) => $n->uid === 'fixture-attendees-series@example.org'));
        self::assertCount(1, $w, 'one warning for 6 dates and a changed date');
        self::assertSame('Kreativität', $w[0]->correctedTo);
    }

    public function testGoogleInvitationSourceSwitch(): void
    {
        if (!is_file(dirname(__DIR__) . '/fixtures/google-invited.ics')) {
            self::markTestSkipped('tests/fixtures/google-invited.ics does not exist yet: it is made in the phase 0 Google experiment (plan section 7, G-06). '
                . 'Then assert here whether the UIDs match the Outlook file, which lines name people (R-12), and how a cancelled date looks.');
        }
        self::fail('google-invited.ics now exists: write the source-switch expectations (plan section 8.4) and remove this line.');
    }

    public function testWindowsTimeZoneNamesBecomeEuropeZurich(): void
    {
        $r = self::read(self::fixture(self::SAMPLE), '2026-11-19 00:00', '2026-11-19', '2027-03-31');
        foreach ($r->occurrences as $o) {
            self::assertSame('Europe/Zurich', $o->start->getTimezone()->getName());
        }
        // Floating times and an unknown zone name are read as Europe/Zurich, never as the server's zone.
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\n"
            . "BEGIN:VEVENT\r\nUID:float@example.org\r\nSUMMARY:Floating\r\nDTSTART:20270201T100000\r\nDTEND:20270201T110000\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:unknown@example.org\r\nSUMMARY:Unknown zone\r\nDTSTART;TZID=Mitteleuropa Nirgendwo:20270202T100000\r\nDTEND;TZID=Mitteleuropa Nirgendwo:20270202T110000\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:utc@example.org\r\nSUMMARY:UTC\r\nDTSTART:20270203T090000Z\r\nDTEND:20270203T100000Z\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
        $r = self::read($ics, self::NOW, self::FROM, self::TO);
        self::assertSame(
            ['2027-02-01T10:00:00+01:00', '2027-02-02T10:00:00+01:00', '2027-02-03T10:00:00+01:00'],
            array_map(static fn ($o) => $o->start->format(DATE_ATOM), $r->occurrences)
        );
    }

    public function testInternalDateOfAPublicSeries(): void
    {
        $override = "BEGIN:VEVENT\r\nUID:union-seniorensport@example\r\nRECURRENCE-ID;TZID=W. Europe Standard Time:20261204T090000\r\n"
            . "SUMMARY:Seniorensport – Raum privat vermietet\r\nDTSTART;TZID=W. Europe Standard Time:20261204T090000\r\nDTEND;TZID=W. Europe Standard Time:20261204T100000\r\n"
            . "CLASS:PRIVATE\r\nEND:VEVENT\r\n";
        $ics = self::insertBeforeEnd(self::fixture(self::SAMPLE), $override);
        $r = self::read($ics, '2026-11-19 00:00', '2026-11-19', '2027-03-31');
        self::assertCount(18, self::byTitle($r->occurrences, 'Seniorensport'));
        self::assertSame(3, $r->droppedInternal);
        $json = json_encode(array_map(static fn ($o) => $o->toArray(), $r->occurrences), JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('privat vermietet', (string) $json);
    }

    public function testFirstRunOnPatternsCreatesOnlyWhatIsDue(): void
    {
        $plan = self::core()->diff(self::targets(self::fixture('recurrence-patterns.ics'), self::NOW, self::FROM, self::TO), []);
        self::assertSame([], $plan->ofType(Action::UNPUBLISH));
        self::assertCount(15, $plan->ofType(Action::CREATE), '3 weekly offers + 12 dated events; Sprechstunde not synced');
    }
}

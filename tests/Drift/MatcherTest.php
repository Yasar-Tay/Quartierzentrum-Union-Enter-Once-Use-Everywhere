<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Drift;

use EnterOnce\Drift\DriftResult;
use EnterOnce\Drift\Matcher;

/** Plan section 8.7 and the hard cases of programme-page-notes.md, with hand-made records. */
final class MatcherTest extends DriftTestCase
{
    /** @param list<\EnterOnce\Drift\Expected> $expected */
    private function compare(array $expected, \EnterOnce\Drift\PageSnapshot $page, string $now = '2026-11-23 00:00'): DriftResult
    {
        return (new Matcher())->match($expected, $page, self::zurich($now), self::window($now));
    }

    /* ---------- 8.7: the listed cases ---------- */

    public function testCalendarEventWithNoCounterpartIsMissingOnTheSite(): void
    {
        $r = $this->compare([self::calDated('Repair Café', '2026-12-05', '14:00', '17:00')], self::page());

        self::assertSame(['Repair Café'], self::titles($r->missing));
        self::assertSame([], $r->different);
        self::assertSame([], $r->onlyOnSite);
    }

    public function testWeeklyOfferWithTimeOffByFifteenMinutesIsPairedAndListedAsDifferent(): void
    {
        $r = $this->compare(
            [self::calWeekly('Deutsch im Alltag', 1, '09:00', '11:00')],
            self::page([], [self::siteWeekly('Deutsch im Alltag A', 1, '09:15', '11:00')])
        );

        self::assertSame([], $r->missing, 'not reported as missing');
        self::assertSame([], $r->onlyOnSite);
        self::assertCount(1, $r->different);
        self::assertSame(['Zeit: Kalender 09:00–11:00 Uhr, Website 09:15–11:00 Uhr.'], $r->different[0]->differences);
        self::assertSame('Deutsch im Alltag A', $r->different[0]->site->title);
    }

    public function testTwoSiteOffersOnTheSameWeekdayAndTimeAreAmbiguousNotGuessed(): void
    {
        $r = $this->compare(
            [self::calWeekly('Kinderpiazza', 1, '14:00', '17:00')],
            self::page([], [
                self::siteWeekly('Kinderpiazza', 1, '14:00', '17:00', i: 1),
                self::siteWeekly('Kinderpiazza', 1, '14:00', '17:00', i: 2),
            ])
        );

        self::assertCount(1, $r->ambiguous);
        self::assertCount(1, $r->ambiguous[0]->expected);
        self::assertCount(2, $r->ambiguous[0]->site);
        self::assertSame([], $r->agreeing);
        self::assertSame([], $r->different);
        self::assertSame([], $r->missing, 'not missing: it is one of the two');
        self::assertSame([], $r->onlyOnSite, 'neither site offer is called orphaned');
    }

    public function testTwoCalendarSeriesAndTwoIdenticalSiteOffersAreAlsoAmbiguous(): void
    {
        $r = $this->compare(
            [self::calWeekly('Kinderpiazza', 1, '14:00', '17:00'), self::calWeekly('Kinderpiazza Saisonbeginn', 1, '14:00', '17:00')],
            self::page([], [self::siteWeekly('Kinderpiazza', 1, '14:00', '17:00', i: 1), self::siteWeekly('Kinderpiazza', 1, '14:00', '17:00', i: 2)])
        );

        self::assertCount(1, $r->ambiguous);
        self::assertCount(2, $r->ambiguous[0]->expected);
        self::assertCount(2, $r->ambiguous[0]->site);
    }

    public function testSiteRecordWithNoCounterpartIsListedAsOnlyOnTheSite(): void
    {
        $r = $this->compare([], self::page([self::siteDated('Atelier Cuisine Globale', '2026-11-29', '09:00', '14:00')], [self::siteWeekly('Wir reden Deutsch', 2, '09:30', '11:00')]));

        self::assertSame(['Atelier Cuisine Globale', 'Wir reden Deutsch'], self::titles($r->onlyOnSite));
    }

    public function testCancelledCalendarEventStillListedIsReported(): void
    {
        $r = $this->compare(
            [self::calDated('Treffpunkt', '2026-12-16', '14:00', '16:00', cancelled: true)],
            self::page([self::siteDated('Treffpunkt', '2026-12-16', '14:00', '16:00')])
        );

        self::assertCount(1, $r->cancelledOnline);
        self::assertSame('Im Kalender abgesagt, auf der Website ohne Vermerk «abgesagt».', $r->cancelledOnline[0]->differences[0]);
        self::assertSame([], $r->different);
        self::assertSame([], $r->agreeing);
    }

    public function testCancelledCalendarEventThatIsNotOnTheSiteIsNotMissing(): void
    {
        $r = $this->compare([self::calDated('Treffpunkt', '2026-12-16', '14:00', '16:00', cancelled: true)], self::page());

        self::assertSame([], $r->missing);
        self::assertSame(1, $r->ignored['cancelled_not_on_site']);
    }

    public function testCancelledOnBothSidesAgrees(): void
    {
        $r = $this->compare(
            [self::calDated('Treffpunkt', '2026-12-16', '14:00', '16:00', cancelled: true)],
            self::page([self::siteDated('Treffpunkt', '2026-12-16', '14:00', '16:00', cancelled: true)])
        );

        self::assertCount(1, $r->agreeing);
        self::assertFalse($r->hasFindings());
    }

    /* ---------- the rest of the matching rules ---------- */

    public function testTitlesMayDifferBetweenCalendarAndSite(): void
    {
        $r = $this->compare(
            [self::calWeekly('Test: Café Balance', 1, '14:15', '15:15'), self::calWeekly('Yoga im Union', 1, '09:00', '10:00')],
            self::page([], [
                self::siteWeekly('Yoga am Montag (Start 09:00 Uhr)', 1, '09:00', '10:00', i: 1),
                self::siteWeekly('Café Bâlance', 1, '14:15', '15:15', i: 2),
            ])
        );

        self::assertCount(2, $r->agreeing);
        self::assertFalse($r->hasFindings());
    }

    public function testOverlappingOffersAreSeparatedByTitleAndTime(): void
    {
        // Yoga (09:00–10:00) overlaps Deutsch (09:15–11:00) on both sides.
        $r = $this->compare(
            [self::calWeekly('Deutsch im Alltag', 1, '09:00', '11:00'), self::calWeekly('Yoga im Union', 1, '09:00', '10:00')],
            self::page([], [
                self::siteWeekly('Deutsch im Alltag A', 1, '09:15', '11:00', i: 1),
                self::siteWeekly('Yoga am Montag (Start 09:00 Uhr)', 1, '09:00', '10:00', i: 2),
            ])
        );

        self::assertSame([], $r->ambiguous);
        self::assertCount(1, $r->agreeing);
        self::assertSame('Yoga am Montag (Start 09:00 Uhr)', $r->agreeing[0]->site->title);
        self::assertCount(1, $r->different);
        self::assertSame('Deutsch im Alltag A', $r->different[0]->site->title);
    }

    public function testOneSiteOfferCoveringTwoCalendarSeriesIsRecognised(): void
    {
        $r = $this->compare(
            [self::calWeekly('Jugendschach', 1, '18:00', '20:00'), self::calWeekly('Schach- und Denkspiele', 1, '20:00', '00:00')],
            self::page([], [self::siteWeekly('Jugendschach & Schach- und Denkspiele', 1, '18:00', '00:00')])
        );

        self::assertSame([], $r->ambiguous);
        self::assertSame([], $r->missing);
        self::assertSame([], $r->onlyOnSite);
        self::assertCount(1, $r->agreeing);
        self::assertTrue($r->agreeing[0]->coversSeveralSeries());
        self::assertCount(2, $r->agreeing[0]->expected);
    }

    public function testOfferThatDoesNotSpanBothSeriesIsNotTreatedAsCovering(): void
    {
        // The second series runs until midnight, the site offer only until 23:00: not one block, so no covering; and no guess.
        $r = $this->compare(
            [self::calWeekly('Jugendschach', 1, '18:00', '20:00'), self::calWeekly('Schach- und Denkspiele', 1, '20:00', '00:00')],
            self::page([], [self::siteWeekly('Jugendschach & Schach- und Denkspiele', 1, '18:00', '23:00')])
        );

        self::assertSame([], $r->agreeing);
        self::assertCount(1, $r->ambiguous);
    }

    public function testTheTitleAloneIsNeverTheKey(): void
    {
        // Same title, other weekday: no pairing.
        $weekly = $this->compare([self::calWeekly('Yoga', 1, '09:00', '10:00')], self::page([], [self::siteWeekly('Yoga', 3, '09:00', '10:00')]));
        self::assertSame(['Yoga'], self::titles($weekly->missing));
        self::assertSame(['Yoga'], self::titles($weekly->onlyOnSite));
        self::assertSame([], $weekly->different);

        // Same weekday, times that do not overlap: no pairing.
        $apart = $this->compare([self::calWeekly('Yoga', 1, '09:00', '10:00')], self::page([], [self::siteWeekly('Yoga', 1, '18:00', '19:00')]));
        self::assertSame(['Yoga'], self::titles($apart->missing));

        // Same title, other day (dated): no pairing.
        $dated = $this->compare([self::calDated('Konzert', '2026-12-05', '20:00', '22:00')], self::page([self::siteDated('Konzert', '2026-12-06', '20:00', '22:00')]));
        self::assertSame(['Konzert'], self::titles($dated->missing));
        self::assertSame(['Konzert'], self::titles($dated->onlyOnSite));
    }

    public function testDatedEventsAreMatchedByDateAndStartTimeEvenWithDifferentTitles(): void
    {
        $r = $this->compare(
            [self::calDated('Brunch Atelier Cuisine Globale', '2026-11-29', '09:00', '14:00')],
            self::page([self::siteDated('Atelier Cuisine Globale', '2026-11-29', '09:00', '14:00')])
        );

        self::assertCount(1, $r->agreeing);
    }

    public function testDatedEventWithChangedTimeIsPairedByTitleOnTheSameDay(): void
    {
        $r = $this->compare(
            [self::calDated('Atelier Cuisine Globale', '2026-11-29', '10:00', '15:00')],
            self::page([self::siteDated('Atelier Cuisine Globale', '2026-11-29', '09:00', '14:00')])
        );

        self::assertCount(1, $r->different);
        self::assertSame(['Zeit: Kalender 10:00–15:00 Uhr, Website 09:00–14:00 Uhr.'], $r->different[0]->differences);
        self::assertSame([], $r->missing);
        self::assertSame([], $r->onlyOnSite);
    }

    public function testDatedEventWithChangedTimeAndUnrelatedTitleIsNotPaired(): void
    {
        $r = $this->compare(
            [self::calDated('Repair Café', '2026-11-29', '10:00', '12:00')],
            self::page([self::siteDated('Gottesdienst', '2026-11-29', '09:00', '10:00')])
        );

        self::assertSame(['Repair Café'], self::titles($r->missing));
        self::assertSame(['Gottesdienst'], self::titles($r->onlyOnSite));
    }

    public function testTwoEventsOnTheSameDayAndTimeAreSeparatedByTitle(): void
    {
        $r = $this->compare(
            [self::calDated('Kinderflohmarkt', '2026-12-05', '14:00', '16:00'), self::calDated('Repair Café', '2026-12-05', '14:00', '16:00')],
            self::page([self::siteDated('Repair Café', '2026-12-05', '14:00', '16:00', i: 1), self::siteDated('Kinderflohmarkt', '2026-12-05', '14:00', '16:00', i: 2)])
        );

        self::assertCount(2, $r->agreeing);
        self::assertSame([], $r->ambiguous);
    }

    public function testTwoEventsOnTheSameDayAndTimeWithIdenticalTitlesAreAmbiguous(): void
    {
        $r = $this->compare(
            [self::calDated('Sprechstunde', '2026-12-05', '14:00', '16:00')],
            self::page([self::siteDated('Sprechstunde', '2026-12-05', '14:00', '16:00', i: 1), self::siteDated('Sprechstunde', '2026-12-05', '14:00', '16:00', i: 2)])
        );

        self::assertCount(1, $r->ambiguous);
        self::assertSame([], $r->agreeing);
    }

    public function testFlagsAreCompared(): void
    {
        $r = $this->compare(
            [
                self::calWeekly('Yoga', 1, '09:00', '10:00', vacation: true),
                self::calWeekly('Tanzen', 2, '19:00', '20:00'),
                self::calWeekly('Schach', 3, '18:00', '19:00', cancelled: true),
            ],
            self::page([], [
                self::siteWeekly('Yoga', 1, '09:00', '10:00', i: 1),
                self::siteWeekly('Tanzen', 2, '19:00', '20:00', vacation: true, i: 2),
                self::siteWeekly('Schach', 3, '18:00', '19:00', i: 3),
            ])
        );

        self::assertSame(['Yoga', 'Tanzen'], array_map(static fn ($p) => $p->expected[0]->title, $r->different));
        self::assertStringContainsString('Ferienpause', $r->different[0]->differences[0]);
        self::assertSame(['Die Website zeigt «Ferienpause», der Kalender nicht.'], $r->different[1]->differences);
        self::assertCount(1, $r->cancelledOnline);
        self::assertStringContainsString('nächste Termin ist im Kalender abgesagt', $r->cancelledOnline[0]->differences[0]);
    }

    public function testSiteShowingCancelledWhileCalendarDoesNotIsDifferent(): void
    {
        $r = $this->compare(
            [self::calDated('Konzert', '2026-12-05', '20:00', '22:00')],
            self::page([self::siteDated('Konzert', '2026-12-05', '20:00', '22:00', cancelled: true)])
        );

        self::assertSame(['Die Website zeigt «abgesagt», der Kalender nicht.'], $r->different[0]->differences);
    }

    public function testSiteWithoutTimeWhereCalendarHasOne(): void
    {
        $r = $this->compare(
            [self::calDated('Konzert', '2026-12-05', '20:00', '22:00')],
            self::page([self::siteDated('Konzert', '2026-12-05', null)])
        );

        // No start time on the site: not the same start, but alike titles on the same day pair them.
        self::assertCount(1, $r->different);
        self::assertStringContainsString('keine Uhrzeit', $r->different[0]->differences[0]);
    }

    public function testAllDayEventsAreNotComparedByTime(): void
    {
        $r = $this->compare(
            [self::calDated('Tag der offenen Tür', '2026-12-05', null, null, allDay: true)],
            self::page([self::siteDated('Tag der offenen Tür', '2026-12-05', null)])
        );

        self::assertCount(1, $r->agreeing);
    }

    /* ---------- what the page could not show ---------- */

    public function testWeeklyOffersMissingFromAnIncompleteListCannotBeJudged(): void
    {
        $r = $this->compare(
            [self::calWeekly('Theaterprobe', 5, '18:00', '20:00')],
            self::page([], [self::siteWeekly('Yoga', 1, '09:00', '10:00')], moreWeekly: true)
        );

        self::assertSame([], $r->missing);
        self::assertSame(['Theaterprobe'], self::titles($r->unverifiable));
    }

    public function testWeeklyOffersMissingFromACompleteListAreMissing(): void
    {
        $r = $this->compare([self::calWeekly('Theaterprobe', 5, '18:00', '20:00')], self::page([], [self::siteWeekly('Yoga', 1, '09:00', '10:00')]));

        self::assertSame(['Theaterprobe'], self::titles($r->missing));
    }

    public function testDatedEventsAfterTheLastShownDayCannotBeJudgedWhenMoreAreAvailable(): void
    {
        $r = $this->compare(
            [self::calDated('Früh', '2026-12-01', '10:00', '11:00'), self::calDated('Spät', '2027-01-20', '10:00', '11:00')],
            self::page([self::siteDated('Anderes', '2026-12-10', '10:00', '11:00')], [], moreDated: true)
        );

        self::assertSame(['Früh'], self::titles($r->missing));
        self::assertSame(['Spät'], self::titles($r->unverifiable));
    }

    public function testPendingWeeklyOffersAreNeverMissing(): void
    {
        $r = $this->compare([self::calWeekly('Neu ab Januar', 1, '10:00', '11:00', pending: true)], self::page());

        self::assertSame([], $r->missing);
        self::assertSame(1, $r->ignored['pending_weekly']);
    }

    public function testSiteRecordsOutsideTheComparedPeriodAreLeftOut(): void
    {
        $r = $this->compare([], self::page([
            self::siteDated('Vergangen', '2026-11-01', '10:00', '11:00', i: 1),
            self::siteDated('Heute', '2026-11-23', '10:00', '11:00', i: 2),
            self::siteDated('Weit weg', '2027-06-01', '10:00', '11:00', i: 3),
        ]));

        self::assertSame(['Heute'], self::titles($r->onlyOnSite));
        self::assertSame(1, $r->ignored['past_on_site']);
        self::assertSame(1, $r->ignored['beyond_window_on_site']);
    }

    public function testCleanComparisonHasNoFindings(): void
    {
        $r = $this->compare(
            [self::calDated('Konzert', '2026-12-05', '20:00', '22:00'), self::calWeekly('Yoga', 1, '09:00', '10:00')],
            self::page([self::siteDated('Konzert', '2026-12-05', '20:00', '22:00')], [self::siteWeekly('Yoga', 1, '09:00', '10:00')])
        );

        self::assertFalse($r->hasFindings());
        self::assertSame(2, $r->counts()['agreeing']);
    }

    public function testComparisonIsDeterministic(): void
    {
        $expected = [self::calWeekly('Kinderpiazza', 1, '14:00', '17:00'), self::calWeekly('Yoga', 1, '09:00', '10:00')];
        $page = self::page([], [self::siteWeekly('Kinderpiazza', 1, '14:00', '17:00', i: 1), self::siteWeekly('Kinderpiazza', 1, '14:00', '17:00', i: 2), self::siteWeekly('Yoga', 1, '09:00', '10:00', i: 3)]);

        self::assertSame($this->compare($expected, $page)->counts(), $this->compare(array_reverse($expected), $page)->counts());
    }
}

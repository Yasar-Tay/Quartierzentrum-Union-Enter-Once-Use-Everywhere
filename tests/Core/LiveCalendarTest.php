<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Core;

use EnterOnce\Core\Action;
use EnterOnce\Core\CalendarResult;
use EnterOnce\Core\Notice;
use EnterOnce\Core\TargetRecord;

/** Plan section 8.2: Union's live test calendar. Window 4 Jan – 31 Mar 2027, now 4 Jan 2027 00:00. */
final class LiveCalendarTest extends CoreTestCase
{
    private const NOW = '2027-01-04 00:00';
    private const FROM = '2027-01-04';
    private const TO = '2027-03-31';

    private const UID_DEUTSCH_MO = '040000008200E00074C5B7101A82E0080000000050756CA84277DD0100000000000000001000000088CC4D047EC66C4CA4C9D244DCA87503';
    private const UID_DEUTSCH_WE = '040000008200E00074C5B7101A82E00800000000E0A7BDD04277DD01000000000000000010000000C3F81EFFB884C6479AA18FF534C59DB1';
    private const UID_DEUTSCH_TU = '040000008200E00074C5B7101A82E00800000000C07B41185068DD0100000000000000001000000008EB6910B7AEB547A2FC907DAD264AE7';

    private function live(string $now = self::NOW, string $to = self::TO, string $from = self::FROM): CalendarResult
    {
        return self::read(self::fixture(self::LIVE), $now, $from, $to);
    }

    public function testWrittenToTheAssociationSite(): void
    {
        $r = $this->live();
        $t = self::core()->targets($r);
        $weekly = $t->ofKind(TargetRecord::WEEKLY_OFFER, TargetRecord::PUBLISH);
        $dated = $t->ofKind(TargetRecord::DATED_EVENT, TargetRecord::PUBLISH);
        self::assertCount(5, $weekly);
        self::assertCount(4, $dated);
        self::assertCount(5, $r->infos());
        self::assertCount(0, $r->warnings());
        self::assertEqualsCanonicalizing(
            ['Test: Deutsch im Alltag', 'Test: Kinderpiazza', 'Test: Konzert Terrible two', 'Test: Café Dialogue', 'Test: Offener Deutschtreff'],
            array_map(static fn (Notice $n) => $n->title, $r->infos())
        );
        foreach ($r->infos() as $n) {
            self::assertSame(Notice::CODE_NO_CATEGORY, $n->code);
        }
        self::assertEqualsCanonicalizing(
            ['Test: Kinderpiazza', 'Test: Deutsch im Alltag', 'Test: Konzert Terrible two', 'Test: Café Dialogue'],
            array_map(static fn ($x) => $x->fields['title'], $dated)
        );
    }

    public function testDeutschImAlltagMondayAndWednesday(): void
    {
        $t = self::core()->targets($this->live());
        $mo = $t->byKey('union:' . self::UID_DEUTSCH_MO . ':MO');
        $we = $t->byKey('union:' . self::UID_DEUTSCH_WE . ':WE');
        self::assertNotNull($mo);
        self::assertNotNull($we);
        self::assertSame(TargetRecord::WEEKLY_OFFER, $mo->kind);
        self::assertSame('09:00', $mo->fields['time_start']);
        self::assertSame('2027-06-21', $mo->fields['last_date']);
        self::assertSame('2027-06-23', $we->fields['last_date']);
        self::assertSame(['Beratung & Bildung'], $mo->fields['topics'], 'the space in «name= Bildung» is tolerated');
        self::assertSame(['Für Erwachsene'], $mo->fields['target_groups']);
        $occ = self::byTitle($this->live()->occurrences, 'Test: Deutsch im Alltag');
        self::assertSame(['Bildung'], $occ[0]->categories);
        self::assertSame(['Deutschlernende', 'Erwachsene'], $occ[0]->tags);
    }

    public function testDeutschImAlltagTuesdaySingle(): void
    {
        $r = $this->live();
        $t = self::core()->targets($r)->byKey('union:' . self::UID_DEUTSCH_TU);
        self::assertNotNull($t);
        self::assertSame(TargetRecord::DATED_EVENT, $t->kind);
        self::assertSame('2027-01-05', $t->fields['date']);
        self::assertSame('09:15', $t->fields['time_start']);
        self::assertStringContainsString('Indoor-Spielplatz', $t->fields['body'], "Kinderpiazza's description is passed through as is");
        $uids = array_map(static fn (Notice $n) => $n->uid, $r->infos());
        self::assertContains(self::UID_DEUTSCH_TU, $uids);
    }

    public function testThreeDeutschImAlltagEntriesStaySeparate(): void
    {
        $t = self::core()->targets($this->live());
        $keys = [];
        foreach ($t->targets as $x) {
            if ($x->fields['title'] === 'Test: Deutsch im Alltag') {
                $keys[] = $x->key;
            }
        }
        self::assertCount(3, array_unique($keys));
    }

    public function testCafeBalance(): void
    {
        $t = self::targetByTitle(self::core()->targets($this->live())->targets, 'Test: Café Balance');
        self::assertSame(TargetRecord::WEEKLY_OFFER, $t->kind);
        self::assertSame(1, $t->fields['weekday']);
        self::assertSame('14:00', $t->fields['time_start']);
        self::assertSame(['Bewegung & Entspannung', 'Offener Treffpunkt'], $t->fields['topics']);
        self::assertSame(['Für Senioren'], $t->fields['target_groups'], 'tag in an unclosed block');
        self::assertStringNotContainsString('name=', $t->fields['body']);
        self::assertStringNotContainsString('[tag]', $t->fields['body']);
    }

    public function testTheaterBlockOrderDoesNotMatter(): void
    {
        $t = self::targetByTitle(self::core()->targets($this->live())->targets, 'Test: Theater Niemandsland');
        self::assertSame(TargetRecord::WEEKLY_OFFER, $t->kind);
        self::assertSame(5, $t->fields['weekday']);
        self::assertSame(['Kulturelles & Musik'], $t->fields['topics']);
        self::assertSame(['Für Erwachsene'], $t->fields['target_groups']);
    }

    public function testOffenerDeutschtreffWeeklyWithInfo(): void
    {
        $r = $this->live();
        $t = self::targetByTitle(self::core()->targets($r)->targets, 'Test: Offener Deutschtreff');
        self::assertSame(TargetRecord::WEEKLY_OFFER, $t->kind);
        self::assertSame('2027-03-25', $t->fields['last_date']);
        self::assertSame([], $t->fields['topics']);
        self::assertContains('Test: Offener Deutschtreff', array_map(static fn (Notice $n) => $n->title, $r->infos()));
    }

    public function testBeforeLeadDaysOnlyDatedEventsAreCreated(): void
    {
        [$plan] = self::sync(self::fixture(self::LIVE), '2026-11-23 00:00', '2026-11-23', '2027-02-23');
        $creates = $plan->ofType(Action::CREATE);
        self::assertCount(4, $creates);
        foreach ($creates as $a) {
            self::assertSame(TargetRecord::DATED_EVENT, $a->kind);
        }
        self::assertCount(4, $plan->changes());
        $t = self::targets(self::fixture(self::LIVE), '2026-11-23 00:00', '2026-11-23', '2027-02-23');
        self::assertSame([], $t->ofKind(TargetRecord::WEEKLY_OFFER), 'every series starts more than 14 days later (R-26)');
        self::assertCount(5, $t->pending);
    }

    public function testLinkRemnantsAreRemoved(): void
    {
        foreach ($this->live()->occurrences as $o) {
            self::assertStringNotContainsString('<mailto:', $o->description);
            self::assertStringNotContainsString('<https://', $o->description);
            self::assertStringNotContainsString('mailto:', $o->descriptionHtml);
            self::assertStringNotContainsString('https://', $o->descriptionHtml);
        }
        $balance = self::byTitle($this->live()->occurrences, 'Test: Café Balance')[0];
        self::assertStringContainsString('md@bs.ch / kontakt@union-basel.ch', $balance->description);
        $deutsch = self::byTitle($this->live()->occurrences, 'Test: Deutsch im Alltag')[0];
        self::assertStringContainsString('kontakt@union-basel.ch oder unter', $deutsch->description);
    }

    public function testLocationWithOnlyARoomName(): void
    {
        $o = self::byTitle($this->live()->occurrences, 'Test: Konzert Terrible two')[0];
        self::assertSame('Bistro Quartierraum', $o->room);
        self::assertSame('Bistro Quartierraum', $o->location);
    }

    public function testSecondRunWithNewDtstampProducesNoActions(): void
    {
        $ics = self::fixture(self::LIVE);
        [$first, $site] = self::sync($ics, self::NOW, self::FROM, self::TO);
        self::assertCount(9, $first->ofType(Action::CREATE));
        $again = str_replace('DTSTAMP:20261123T145911Z', 'DTSTAMP:20261124T081500Z', $ics);
        self::assertNotSame($ics, $again);
        [$second] = self::sync($again, '2027-01-04 00:15', self::FROM, self::TO, $site);
        self::assertSame([], $second->changes());
    }

    public function testOccurrenceCount(): void
    {
        // 13 Mondays × 2 series + 13 Wednesdays + 12 Thursdays + 12 Fridays + 4 single events.
        self::assertCount(67, $this->live()->activeOccurrences());
    }
}

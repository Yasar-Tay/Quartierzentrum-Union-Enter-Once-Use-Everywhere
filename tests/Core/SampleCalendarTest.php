<?php

declare(strict_types=1);

namespace EnterOnce\Tests\Core;

use EnterOnce\Core\Action;
use EnterOnce\Core\Notice;
use EnterOnce\Core\SiteRecord;
use EnterOnce\Core\TargetRecord;
use EnterOnce\Core\Window;

/** Plan section 8.1: the organisers' sample calendar. Window 19 Nov 2026 – 31 Mar 2027, now 19 Nov 2026 00:00. */
final class SampleCalendarTest extends CoreTestCase
{
    private const NOW = '2026-11-19 00:00';
    private const FROM = '2026-11-19';
    private const TO = '2027-03-31';

    private function sample(array $settings = [], string $now = self::NOW): \EnterOnce\Core\CalendarResult
    {
        return self::read(self::fixture(self::SAMPLE), $now, self::FROM, self::TO, $settings);
    }

    public function testTotals(): void
    {
        $r = $this->sample();
        self::assertCount(71, $r->activeOccurrences(), '17 + 19 + 12 + 19 + 4');
        self::assertSame(2, $r->droppedInternal);
        self::assertCount(1, $r->cancelledOccurrences());
        self::assertCount(1, $r->warnings());
        self::assertCount(1, $r->infos());
        self::assertCount(2, $r->notices);
    }

    public function testWithoutR01TheCountWouldBe73(): void
    {
        $r = $this->sample(['internal_markers' => ['class_not_public' => false, 'categories' => []]]);
        self::assertCount(73, $r->activeOccurrences());
        self::assertSame(0, $r->droppedInternal);
    }

    public function testOffenerDeutschtreff(): void
    {
        $dates = self::byTitle($this->sample()->occurrences, 'Offener Deutschtreff');
        self::assertCount(17, $dates);
        $days = array_map(static fn ($o) => $o->start->format('Y-m-d'), $dates);
        self::assertNotContains('2026-12-24', $days);
        self::assertNotContains('2026-12-31', $days);
        self::assertSame(['Bildung', 'Offener Treffpunkt'], $dates[0]->categories);
        self::assertSame(['Deutschlernende', 'Erwachsene'], $dates[0]->tags);
        self::assertSame('union:union-deutschtreff@example:2026-11-19', $dates[0]->syncKey);
        $series = array_values(array_filter($this->sample()->series, static fn ($s) => $s->uid === 'union-deutschtreff@example'))[0];
        self::assertSame(['2026-12-24', '2026-12-31'], $series->toArray()['skipped_dates']);
        self::assertSame(17, $series->toArray()['date_count']);
    }

    public function testYogaMovedDateAndClockChange(): void
    {
        $dates = self::byTitle($this->sample()->occurrences, 'Yoga im Union');
        self::assertCount(19, $dates);
        foreach ($dates as $o) {
            if ($o->originalDate === '2026-12-07') {
                self::assertSame('2026-12-07T10:00:00+01:00', $o->start->format(DATE_ATOM));
                self::assertSame('2026-12-07T11:00:00+01:00', $o->end->format(DATE_ATOM));
                self::assertSame('Grosser Saal', $o->room);
                self::assertTrue($o->moved);
                self::assertSame('union:union-yoga@example:2026-12-07', $o->syncKey);
            } else {
                self::assertSame('09:00', $o->start->format('H:i'), $o->originalDate);
                self::assertSame('10:00', $o->end->format('H:i'));
                self::assertSame('Oberer Saal', $o->room);
                self::assertFalse($o->moved);
            }
        }
        $last = $dates[count($dates) - 1];
        self::assertSame('2027-03-29T09:00:00+02:00', $last->start->format(DATE_ATOM), 'after the clock change still 09:00 local');
    }

    public function testKinderpiazza(): void
    {
        $dates = self::byTitle($this->sample()->occurrences, 'Kinderpiazza');
        self::assertCount(12, $dates);
        self::assertSame('2027-02-08', $dates[11]->start->format('Y-m-d'));
        self::assertCount(3, $dates[0]->categories);
        self::assertCount(3, $dates[0]->tags);
    }

    public function testSeniorensportAndSingles(): void
    {
        $r = $this->sample();
        self::assertCount(19, self::byTitle($r->occurrences, 'Seniorensport'));

        $bar = self::byTitle($r->occurrences, 'MittwochsBAR im Union');
        self::assertCount(1, $bar);
        self::assertSame(['Musik', 'Party'], $bar[0]->categories);
        self::assertSame(['Erwachsene', 'Highlight'], $bar[0]->tags);
        self::assertNull($bar[0]->seriesUid);
        self::assertSame('union:union-mittwochsbar-1125@example', $bar[0]->syncKey);

        self::assertCount(1, self::byTitle($r->occurrences, 'Brunch Atelier Cuisine Globale'));

        $theater = self::byTitle($r->occurrences, 'Theater Niemandsland');
        self::assertCount(1, $theater);
        self::assertSame('', $theater[0]->description);
        self::assertSame([], $theater[0]->categories);

        $repair = self::byTitle($r->occurrences, 'Repair Café');
        self::assertCount(1, $repair);
        self::assertSame(['Kultur'], $repair[0]->categories);
    }

    public function testNotices(): void
    {
        $r = $this->sample();
        $info = $r->infos()[0];
        self::assertSame(Notice::CODE_NO_CATEGORY, $info->code);
        self::assertSame('Theater Niemandsland', $info->title);
        $warning = $r->warnings()[0];
        self::assertSame(Notice::CODE_VALUE_CORRECTED, $warning->code);
        self::assertSame('Repair Café', $warning->title);
        self::assertSame('Kulturr', $warning->value);
        self::assertSame('Kultur', $warning->correctedTo);
    }

    public function testListeningSessionIsCancelledWithoutNotice(): void
    {
        $r = $this->sample();
        $cancelled = $r->cancelledOccurrences();
        self::assertCount(1, $cancelled);
        self::assertSame('Listening-Session: Was uns inspiriert', $cancelled[0]->title, '«Abgesagt:» prefix removed');
        foreach ($r->notices as $n) {
            self::assertStringNotContainsString('Listening', $n->title);
        }
        $targets = self::core()->targets($r);
        $t = $targets->byKey('union:union-listening-1126@example');
        self::assertNotNull($t);
        self::assertSame(TargetRecord::DRAFT, $t->status);

        // Not published: a first run creates nothing for it.
        $plan = self::core()->diff($targets, []);
        foreach ($plan->actions as $a) {
            self::assertNotSame('union:union-listening-1126@example', $a->key);
        }
        // Unpublished if it was online.
        $online = new SiteRecord('union:union-listening-1126@example', TargetRecord::DATED_EVENT, 'x', 'publish', 0, 'union');
        $plan = self::core()->diff($targets, [$online]);
        $a = $plan->ofType(Action::UNPUBLISH);
        self::assertCount(1, $a);
        self::assertSame('cancelled', $a[0]->reason);
    }

    public function testInternalEntriesNowhere(): void
    {
        $core = self::core();
        $r = $core->read(self::fixture(self::SAMPLE), self::at(self::NOW), Window::fromDates(self::FROM, self::TO));
        $all = json_encode([
            array_map(static fn ($o) => $o->toArray(), $r->occurrences),
            array_map(static fn ($s) => $s->toArray(), $r->series),
            array_map(static fn ($n) => $n->toArray(), $r->notices),
            array_map(static fn ($t) => $t->toArray(), $core->targets($r)->targets),
            $core->feed($r->occurrences),
        ], JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('Teamsitzung', $all);
        self::assertStringNotContainsString('Geburtstagsfeier', $all);
        self::assertStringNotContainsString('union-intern', $all);
    }

    public function testWrittenToTheAssociationSite(): void
    {
        $t = self::core()->targets($this->sample());
        $weekly = $t->ofKind(TargetRecord::WEEKLY_OFFER, TargetRecord::PUBLISH);
        $dated = $t->ofKind(TargetRecord::DATED_EVENT, TargetRecord::PUBLISH);
        self::assertSame(
            ['Offener Deutschtreff', 'Yoga im Union', 'Kinderpiazza', 'Seniorensport'],
            array_map(static fn ($x) => $x->fields['title'], $weekly)
        );
        self::assertEqualsCanonicalizing(
            ['MittwochsBAR im Union', 'Brunch Atelier Cuisine Globale', 'Theater Niemandsland', 'Repair Café'],
            array_map(static fn ($x) => $x->fields['title'], $dated)
        );
        self::assertSame([], $t->pending);

        $deutsch = self::targetByTitle($weekly, 'Offener Deutschtreff');
        self::assertSame('union:union-deutschtreff@example:TH', $deutsch->key);
        self::assertSame(4, $deutsch->fields['weekday']);
        self::assertSame('15:00', $deutsch->fields['time_start']);
        self::assertSame('17:00', $deutsch->fields['time_end']);
        self::assertSame(['Beratung & Bildung', 'Offener Treffpunkt'], $deutsch->fields['topics']);
        self::assertSame(['Für Erwachsene'], $deutsch->fields['target_groups'], 'Deutschlernende is not transferred');

        $kinder = self::targetByTitle($weekly, 'Kinderpiazza');
        self::assertSame(['Für Kinder', 'Für Erwachsene', 'Für Babys und Kleinkinder'], $kinder->fields['target_groups'], 'Familie → Kinder + Erwachsene');

        $bar = self::targetByTitle($dated, 'MittwochsBAR im Union');
        self::assertSame(['Kulturelles & Musik', 'Märkte & Feste'], $bar->fields['topics']);
        self::assertSame(['Für Erwachsene'], $bar->fields['target_groups'], 'Highlight is not transferred');

        $plan = self::core()->diff($t, []);
        self::assertSame(8, count($plan->ofType(Action::CREATE)));
        self::assertSame(8, count($plan->changes()));
    }

    public function testFerienpauseTwiceInARow(): void
    {
        $flag = function (string $now): array {
            $t = self::core()->targets($this->sample([], $now))->byKey('union:union-deutschtreff@example:TH');

            return [$t->fields['ferienpause'], $t->nextDate];
        };
        self::assertSame([false, '2026-12-17'], $flag('2026-12-17 16:59'));
        self::assertSame([true, '2026-12-24'], $flag('2026-12-17 17:00'), 'from the end of the 17 December date');
        self::assertSame([true, '2026-12-24'], $flag('2026-12-24 16:59'));
        self::assertSame([true, '2026-12-31'], $flag('2026-12-24 17:00'), 'R-21 a second time');
        self::assertSame([true, '2026-12-31'], $flag('2026-12-31 16:59'));
        self::assertSame([false, '2027-01-07'], $flag('2026-12-31 17:00'), 'until 31 December has passed');
    }

    public function testYogaMovedDateNote(): void
    {
        $note = function (string $now): ?string {
            return self::core()->targets($this->sample([], $now))->byKey('union:union-yoga@example:MO')->fields['note'];
        };
        $text = 'Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr im Raum Grosser Saal';
        self::assertNull($note('2026-11-30 09:59'));
        self::assertSame($text, $note('2026-11-30 10:00'), 'from the end of the 30 November date');
        self::assertSame($text, $note('2026-12-07 10:59'));
        self::assertNull($note('2026-12-07 11:00'), 'until 7 December has passed');

        $t = self::core()->targets($this->sample([], '2026-12-01 12:00'))->byKey('union:union-yoga@example:MO');
        self::assertStringStartsWith('<!-- enteronce:note --><p class="enteronce-note">' . $text . '</p><!-- /enteronce:note --><p>Sanftes Yoga', $t->fields['body']);
        self::assertSame('09:00', $t->fields['time_start'], 'the weekly record keeps its regular time');
        self::assertSame('Oberer Saal', $t->fields['room']);
    }

    public function testKinderpiazzaSetToDraftAfterLastDate(): void
    {
        $before = self::core()->targets($this->sample([], '2027-02-08 16:59'))->byKey('union:union-kinderpiazza@example:MO');
        self::assertSame(TargetRecord::PUBLISH, $before->status);
        $after = self::core()->targets($this->sample([], '2027-02-08 17:00'))->byKey('union:union-kinderpiazza@example:MO');
        self::assertSame(TargetRecord::DRAFT, $after->status);
        self::assertSame('series_ended', $after->statusReason);

        $site = [SiteRecord::fromTarget($before)];
        $plan = self::core()->diff(self::core()->targets($this->sample([], '2027-02-08 17:00')), $site);
        $un = $plan->ofType(Action::UNPUBLISH);
        self::assertCount(1, $un);
        self::assertSame('union:union-kinderpiazza@example:MO', $un[0]->key);
    }

    public function testFeedHasAll71Occurrences(): void
    {
        $core = self::core();
        $r = $core->read(self::fixture(self::SAMPLE), self::at(self::NOW), Window::fromDates(self::FROM, self::TO));
        $feed = $core->feed($r->occurrences);
        self::assertCount(71, $feed);
        $starts = array_map(static fn ($i) => strtotime($i['start']), $feed);
        $sorted = $starts;
        sort($sorted);
        self::assertSame($sorted, $starts, 'sorted by start');
        self::assertSame(
            ['id', 'title', 'start', 'end', 'all_day', 'room', 'location', 'description_html', 'categories', 'tags', 'image', 'cancelled', 'series_id'],
            array_keys($feed[0])
        );
        foreach ($feed as $item) {
            self::assertNull($item['image']);
            self::assertFalse($item['cancelled']);
        }
        $first = $feed[0];
        self::assertSame('union:union-deutschtreff@example:2026-11-19', $first['id']);
        self::assertSame('2026-11-19T15:00:00+01:00', $first['start']);
        self::assertSame('union:union-deutschtreff@example', $first['series_id']);

        $badge = self::core(['cancel_mode' => 'badge'])->feed($r->occurrences);
        self::assertCount(72, $badge, 'with cancel_mode badge the cancelled date is shown');
    }
}

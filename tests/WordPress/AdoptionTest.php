<?php

declare(strict_types=1);

namespace EnterOnce\Tests\WordPress;

use EnterOnce\WordPress\Adoption;
use EnterOnce\WordPress\EventAdapter;
use EnterOnce\WordPress\Options;

/** Plan 4.7 and A-14: adoption of Union's hand-entered weekly offers. */
final class AdoptionTest extends WpTestCase
{
    private const KEY_MO = 'union:040000008200E00074C5B7101A82E0080000000050756CA84277DD0100000000000000001000000088CC4D047EC66C4CA4C9D244DCA87503:MO';

    private function adoption(): Adoption
    {
        return new Adoption(Options::get(), Options::fieldMap(Options::get()));
    }

    /** @return array<string, mixed> */
    private function offer(string $slug): array
    {
        foreach ($this->adoption()->siteOffers('union') as $o) {
            if ($o['id'] === $this->seeded[$slug]) {
                return $o;
            }
        }
        self::fail("offer $slug not listed");
    }

    public function testCandidatesAreListedButNeverPairedAutomatically(): void
    {
        $this->configure([], true);
        $this->sync(self::fixture('calendar.ics'), '2027-01-04 00:00');
        $offers = $this->adoption()->siteOffers('union');
        self::assertCount(8, $offers, 'the 8 hand-entered weekly offers of Union');
        $series = $this->adoption()->series('union');
        self::assertCount(5, $series);

        $titles = static fn (array $c) => array_map(static fn ($s) => $s['title'] . ' ' . $s['time_start'], $c);
        self::assertSame(['Test: Deutsch im Alltag 09:00'], $titles(Adoption::candidates($this->offer('deutsch-fuer-den-alltag-2'), $series)));
        self::assertSame(['Test: Café Balance 14:00'], $titles(Adoption::candidates($this->offer('kinderpiazza-17'), $series)), 'listed only; the administrator decides');
        self::assertSame(['Test: Café Balance 14:00'], $titles(Adoption::candidates($this->offer('cafe-balance-8'), $series)));
        self::assertSame([], Adoption::candidates($this->offer('spielend-deutsch-lernen-6'), $series), 'no Tuesday series');
        foreach ($offers as $o) {
            self::assertSame('', (string) get_post_meta($o['id'], EventAdapter::META_MANAGED, true), 'nothing paired by itself');
        }
        self::assertTrue(Adoption::overlap('18:00', '00:00', '19:00', '20:00'), 'an end at midnight');
        self::assertFalse(Adoption::overlap('09:00', '10:00', '10:00', '11:00'));
    }

    public function testAdoptKeepsTextAndTheSyncMaintainsTheRest(): void
    {
        $this->configure([], true);
        $this->sync(self::fixture('calendar.ics'), '2027-01-04 00:00');
        $id = $this->seeded['deutsch-fuer-den-alltag-2'];
        $before = get_post($id);
        $series = array_values(array_filter($this->adoption()->series('union'), static fn ($s) => $s['key'] === self::KEY_MO))[0];

        $rows = $this->adoption()->compare($id, $series, true);
        $byLabel = array_column($rows, null, 'label');
        self::assertSame(['Deutsch im Alltag A', 'Test: Deutsch im Alltag', false, true], [$byLabel['Titel']['site'], $byLabel['Titel']['calendar'], $byLabel['Titel']['same'], $byLabel['Titel']['kept']]);
        self::assertSame(['09:15', '09:00', false], [$byLabel['Beginn']['site'], $byLabel['Beginn']['calendar'], $byLabel['Beginn']['same']]);
        self::assertSame('Montag', $byLabel['Wochentag']['site']);

        self::assertTrue($this->adoption()->adopt($id, 'union', self::KEY_MO, true, $this->clock->now()));
        self::assertNotEmpty(wp_get_post_revisions($id), 'a revision before the first write');
        $backup = json_decode((string) get_post_meta($id, EventAdapter::META_BACKUP, true), true);
        self::assertSame('Deutsch im Alltag A', $backup['title']);
        self::assertSame('0915', $backup['meta']['_qtp_t1'][0]);

        // One offer ↔ one series.
        self::assertInstanceOf(\WP_Error::class, $this->adoption()->adopt($this->seeded['deutsch-fuer-den-alltag'], 'union', self::KEY_MO, true, $this->clock->now()));
        self::assertInstanceOf(\WP_Error::class, $this->adoption()->adopt($id, 'union', self::KEY_MO, true, $this->clock->now()));
        // A series whose weekday or time does not fit is refused.
        $we = array_values(array_filter($this->adoption()->series('union'), static fn ($s) => (int) $s['weekday'] === 3))[0];
        self::assertInstanceOf(\WP_Error::class, $this->adoption()->adopt($this->seeded['deutsch-fuer-den-alltag'], 'union', $we['key'], true, $this->clock->now()));

        // Dry run: the adopted offer is planned as an update, not a create.
        $dry = $this->sync(self::fixture('calendar.ics'), '2027-01-04 00:10');
        self::assertTrue($dry->dryRun);
        $planned = array_values(array_filter($dry->plan->actions, static fn ($a) => $a->key === self::KEY_MO));
        self::assertSame('update', $planned[0]->type);
        self::assertSame($id, (int) $planned[0]->siteId);

        // Live: time, flags and terms from the calendar; title and text stay.
        $untouched = [$this->seeded['kinderpiazza-17'] => self::allMeta($this->seeded['kinderpiazza-17'])];
        $this->configure();
        $report = $this->sync(self::fixture('calendar.ics'), '2027-01-04 00:20');
        self::assertSame(8, $report->counts['create'], '4 weekly + 4 dated; Monday German series is the adopted record');
        self::assertSame(1, $report->counts['update']);
        self::assertSame($id, self::byKey(self::KEY_MO));
        self::assertSame('Deutsch im Alltag A', get_the_title($id));
        self::assertSame($before->post_content, get_post_field('post_content', $id));
        self::assertSame('0900', get_post_meta($id, '_qtp_t1', true));
        self::assertSame('1100', get_post_meta($id, '_qtp_t2', true));
        self::assertSame(['Beratung & Bildung'], array_map('html_entity_decode', wp_get_object_terms($id, 'topic', ['fields' => 'names'])));
        self::assertSame('<p>Kontakt: info@example.org</p>', get_post_meta($id, '_qtp_orginfo', true), 'the sidebar text of an adopted record is kept too');
        self::assertSame('publish', get_post_status($id));
        foreach ($untouched as $other => $meta) {
            self::assertSame($meta, self::allMeta($other), 'offers left alone stay untouched (R-04)');
        }
        // Second run: nothing.
        $again = $this->sync(self::fixture('calendar.ics'), '2027-01-04 00:30');
        self::assertSame(0, $again->counts['create'] + $again->counts['update'] + $again->counts['unpublish']);
    }

    public function testAdoptWithoutKeepTextTakesTheCalendarText(): void
    {
        $this->configure([], true);
        $this->sync(self::fixture('calendar.ics'), '2027-01-04 00:00');
        $id = $this->seeded['deutsch-fuer-den-alltag-2'];
        self::assertTrue($this->adoption()->adopt($id, 'union', self::KEY_MO, false, $this->clock->now()));
        $this->configure();
        $this->sync(self::fixture('calendar.ics'), '2027-01-04 00:10');
        self::assertSame('Test: Deutsch im Alltag', get_the_title($id));
        self::assertStringContainsString('Übe dein Deutsch kostenlos!', get_post_field('post_content', $id));
    }
}

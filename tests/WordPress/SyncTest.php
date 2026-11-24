<?php

declare(strict_types=1);

namespace EnterOnce\Tests\WordPress;

use EnterOnce\WordPress\EventAdapter;
use EnterOnce\WordPress\State;

/** Phase 3 "Done when": both test calendars sync end to end with the fixed "now" values of plan section 8. */
final class SyncTest extends WpTestCase
{
    public function testSampleCalendarEndToEnd(): void
    {
        // Plan 8.1's window (19 Nov – 31 Mar) is longer than the default 3 months.
        $this->configure(['window_months_ahead' => 5]);
        $before = self::programme();
        $report = $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        self::assertFalse($report->dryRun);
        self::assertSame(8, $report->counts['create']);
        self::assertCount(8, self::managed('union', 'publish'));

        $rows = self::programme();
        $weekly = array_column(array_filter($rows, static fn ($r) => $r['section'] === 'weekly'), null, 'id');
        $dated = array_filter($rows, static fn ($r) => $r['section'] === 'dated');

        // Weekly offers: one card each, weekday and regular time.
        $expectWeekly = [
            'Offener Deutschtreff' => ['Donnerstag', '15:00 - 17:00 Uhr'],
            'Yoga im Union' => ['Montag', '09:00 - 10:00 Uhr'],
            'Kinderpiazza' => ['Montag', '14:00 - 17:00 Uhr'],
            'Seniorensport' => ['Freitag', '09:00 - 10:00 Uhr'],
        ];
        foreach ($expectWeekly as $title => [$day, $time]) {
            $id = self::byKey('union:' . match ($title) {
                'Offener Deutschtreff' => 'union-deutschtreff@example:TH',
                'Yoga im Union' => 'union-yoga@example:MO',
                'Kinderpiazza' => 'union-kinderpiazza@example:MO',
                'Seniorensport' => 'union-seniorensport@example:FR',
            });
            self::assertNotNull($id, $title);
            self::assertArrayHasKey($id, $weekly, "$title on the programme page");
            self::assertSame([$title, $day, $time, ''], [$weekly[$id]['title'], $weekly[$id]['day'], $weekly[$id]['time'], $weekly[$id]['band']]);
        }
        // Dated events.
        $expectDated = [
            'MittwochsBAR im Union' => ['Mi., 25.11.2026', '19:00 - 23:00'],
            'Brunch Atelier Cuisine Globale' => ['So., 29.11.2026', '09:00 - 14:30'],
            'Theater Niemandsland' => ['Fr., 27.11.2026', '18:00 - 22:00'],
            'Repair Café' => ['Sa., 05.12.2026', '13:00 - 17:00'],
        ];
        foreach ($expectDated as $title => [$day, $time]) {
            $row = array_values(array_filter($dated, static fn ($r) => $r['title'] === $title));
            self::assertCount(1, $row, $title);
            self::assertSame([$day, $time], [$row[0]['day'], $row[0]['time']], $title);
        }
        // Cancelled and internal entries are nowhere; the hand-entered records are untouched.
        $titles = array_column($rows, 'title');
        foreach (['Listening-Session: Was uns inspiriert', 'Teamsitzung Union', 'Raummiete: Private Geburtstagsfeier'] as $absent) {
            self::assertNotContains($absent, $titles);
        }
        self::assertCount(count($before) + 8, $rows);

        // Taxonomies and the sidebar text.
        $bar = self::byKey('union:union-mittwochsbar-1125@example');
        self::assertEqualsCanonicalizing(['Kulturelles & Musik', 'Märkte & Feste'], array_map('html_entity_decode', wp_get_object_terms($bar, 'topic', ['fields' => 'names'])));
        self::assertSame(['Für Erwachsene'], wp_get_object_terms($bar, 'targetgroup', ['fields' => 'names']));
        self::assertSame(['Quartierzentrum Union'], wp_get_object_terms($bar, 'location_cat', ['fields' => 'names']));
        self::assertStringContainsString('Bistro Union, Quartierzentrum Union, Klybeckstrasse 95', (string) get_post_meta($bar, '_qtp_orginfo', true));
        self::assertSame('1', get_post_meta($bar, EventAdapter::META_MANAGED, true));
        self::assertSame('union', get_post_meta($bar, EventAdapter::META_SOURCE, true));
        self::assertNotSame('', get_post_meta($bar, EventAdapter::META_HASH, true));
        self::assertSame('0', get_post_meta($bar, EventAdapter::META_MISSING, true));
        $kinder = self::byKey('union:union-kinderpiazza@example:MO');
        self::assertEqualsCanonicalizing(['Für Kinder', 'Für Erwachsene', 'Für Babys und Kleinkinder'], wp_get_object_terms($kinder, 'targetgroup', ['fields' => 'names']));

        // State and stored occurrence list.
        $state = State::get('union');
        self::assertSame(['events_in_file' => 12, 'dropped_internal' => 2, 'occurrences_active' => 71, 'occurrences_cancelled' => 1, 'cancelled_events' => 1, 'series' => 4, 'warnings' => 1, 'infos' => 1], $state['summary']);
        self::assertCount(72, State::occurrences('union')['occurrences']);
    }

    public function testSecondRunChangesNothing(): void
    {
        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        $snapshot = self::snapshotEvents();
        $again = str_replace('DTSTAMP:20261117T120000Z', 'DTSTAMP:20261119T001500Z', self::fixture('union-beispielkalender.ics'));
        $report = $this->sync($again, '2026-11-19 00:15');
        self::assertSame(0, $report->counts['create'] + $report->counts['update'] + $report->counts['unpublish'] + $report->counts['hold']);
        self::assertSame([], $report->changedPostIds);
        self::assertSame($snapshot, self::snapshotEvents());
    }

    public function testWeeklyFlagsAndNotesAppearOnTheProgrammePage(): void
    {
        $ics = self::fixture('union-beispielkalender.ics');
        $this->sync($ics, '2026-11-19 00:00');
        $deutsch = self::byKey('union:union-deutschtreff@example:TH');
        $yoga = self::byKey('union:union-yoga@example:MO');
        $kinder = self::byKey('union:union-kinderpiazza@example:MO');

        // R-21: Ferienpause from the end of the 17 December date until 31 December has passed.
        $this->sync($ics, '2026-12-17 17:00');
        self::assertSame('Ferienpause', self::programmeRows('Offener Deutschtreff')[0]['band']);
        self::assertSame('1', get_post_meta($deutsch, '_qtp_pause', true));
        $this->sync($ics, '2026-12-31 17:00');
        self::assertSame('', self::programmeRows('Offener Deutschtreff')[0]['band']);
        self::assertFalse(metadata_exists('post', $deutsch, '_qtp_pause'), 'switch off = not stored, as the tool does');

        // R-24: the moved-date note on top of the body, regular time kept.
        $this->sync($ics, '2026-11-30 10:00');
        $content = get_post_field('post_content', $yoga);
        self::assertStringContainsString('Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr im Raum Grosser Saal', $content);
        self::assertSame('Montag', self::programmeRows('Yoga im Union')[0]['day']);
        self::assertSame('09:00 - 10:00 Uhr', self::programmeRows('Yoga im Union')[0]['time']);
        $this->sync($ics, '2026-12-07 11:00');
        self::assertStringNotContainsString('ausnahmsweise', get_post_field('post_content', $yoga));

        // R-23: Kinderpiazza is set to draft after 8 February 2027.
        $this->sync($ics, '2027-02-08 17:00');
        self::assertSame('draft', get_post_status($kinder));
        self::assertSame([], self::programmeRows('Kinderpiazza', 'Quartierzentrum Union') === [] ? [] : array_filter(self::programmeRows('Kinderpiazza'), static fn ($r) => $r['id'] === $kinder));
    }

    public function testLiveCalendarEndToEnd(): void
    {
        $ics = self::fixture('calendar.ics');
        $report = $this->sync($ics, '2027-01-04 00:00');
        self::assertSame(9, $report->counts['create']);
        $weekly = array_filter(self::programme(), static fn ($r) => $r['section'] === 'weekly' && str_starts_with($r['title'], 'Test: '));
        $dated = array_filter(self::programme(), static fn ($r) => $r['section'] === 'dated' && str_starts_with($r['title'], 'Test: '));
        self::assertCount(5, $weekly);
        self::assertCount(4, $dated);
        self::assertEqualsCanonicalizing(
            ['Montag|09:00 - 11:00 Uhr|Test: Deutsch im Alltag', 'Mittwoch|09:00 - 11:00 Uhr|Test: Deutsch im Alltag', 'Montag|14:00 - 15:00 Uhr|Test: Café Balance', 'Donnerstag|15:00 - 17:00 Uhr|Test: Offener Deutschtreff', 'Freitag|18:00 - 21:00 Uhr|Test: Theater Niemandsland'],
            array_map(static fn ($r) => $r['day'] . '|' . $r['time'] . '|' . $r['title'], $weekly)
        );
        self::assertEqualsCanonicalizing(
            ['Mo., 04.01.2027|Test: Kinderpiazza', 'Di., 05.01.2027|Test: Deutsch im Alltag', 'Di., 05.01.2027|Test: Konzert Terrible two', 'Mi., 06.01.2027|Test: Café Dialogue'],
            array_map(static fn ($r) => $r['day'] . '|' . $r['title'], $dated)
        );
        $state = State::get('union');
        self::assertSame(5, $state['summary']['infos']);
        self::assertSame(0, $state['summary']['warnings']);

        $snapshot = self::snapshotEvents();
        $again = str_replace('DTSTAMP:20261123T145911Z', 'DTSTAMP:20270104T001500Z', $ics);
        $second = $this->sync($again, '2027-01-04 00:15');
        self::assertSame(0, $second->counts['create'] + $second->counts['update'] + $second->counts['unpublish']);
        self::assertSame($snapshot, self::snapshotEvents());
    }

    public function testLiveCalendarBeforeLeadDays(): void
    {
        $report = $this->sync(self::fixture('calendar.ics'), '2026-11-23 00:00');
        self::assertSame(4, $report->counts['create']);
        $weekly = array_filter(self::programme(), static fn ($r) => $r['section'] === 'weekly' && str_starts_with($r['title'], 'Test: '));
        self::assertSame([], $weekly, 'R-26: no weekly offer yet');
    }

    public function testDeletedEventIsUnpublishedAfterTwoRunsAndReturns(): void
    {
        $ics = self::fixture('union-beispielkalender.ics');
        $this->sync($ics, '2026-11-19 00:00');
        $brunch = self::byKey('union:union-brunch-1129@example');
        $without = (string) preg_replace('/BEGIN:VEVENT\r\nUID:union-brunch-1129@example.*?END:VEVENT\r\n/s', '', $ics);
        self::assertNotSame($ics, $without);
        $this->sync($without, '2026-11-19 00:15');
        self::assertSame('publish', get_post_status($brunch));
        self::assertSame('1', get_post_meta($brunch, EventAdapter::META_MISSING, true));
        $this->sync($without, '2026-11-19 00:30');
        self::assertSame('draft', get_post_status($brunch), 'R-05, R-06: draft, not deleted');
        $this->sync($ics, '2026-11-19 00:45');
        self::assertSame('publish', get_post_status($brunch), 'the same record is published again');
        self::assertSame('0', get_post_meta($brunch, EventAdapter::META_MISSING, true));
    }

    public function testFailedDownloadChangesNothing(): void
    {
        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        $snapshot = self::snapshotEvents();
        foreach (['', "<html>Fehler</html>", substr(self::fixture('union-beispielkalender.ics'), 0, 3000)] as $broken) {
            $this->clock->set('2026-11-19 00:15');
            $this->fetcher->set(self::UNION_URL, $broken);
            $report = (new \EnterOnce\WordPress\Runner($this->clock, $this->fetcher, new \EnterOnce\WordPress\Notifier($this->clock)))->run('union');
            self::assertSame('error', $report->status);
            self::assertSame($snapshot, self::snapshotEvents());
        }
        $this->fetcher->set(self::UNION_URL, new \EnterOnce\WordPress\FetchError('Der Kalender-Server hat mit Status 500 geantwortet.'));
        $report = (new \EnterOnce\WordPress\Runner($this->clock, $this->fetcher))->run('union');
        self::assertSame('error', $report->status);
        self::assertSame($snapshot, self::snapshotEvents());
        self::assertNotNull(State::get('union')['error']);
    }

    public function testToolSeriesGenerationIsNeverTriggered(): void
    {
        // An administrator presses «Jetzt abgleichen» on a form that happens to carry the tool's series fields.
        $admin = get_user_by('login', 'admin');
        wp_set_current_user($admin->ID);
        $_POST = $_REQUEST = [
            'qtp_standin_nonce' => wp_create_nonce('qtp_standin_save'),
            'qx_a' => '2027-01-01', 'qx_rep' => 'weekly', 'qx_rep_until' => '2027-03-01', 'qx_extra' => "2027-04-01\n2027-05-01",
        ];
        $countBefore = count(get_posts(['post_type' => 'events', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']));
        $report = $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        $countAfter = count(get_posts(['post_type' => 'events', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids']));
        self::assertSame($countBefore + 8, $countAfter, 'R-10: exactly the 8 records, no generated copies');
        self::assertSame([], get_posts(['post_type' => 'events', 'post_status' => 'any', 'meta_key' => '_qtp_generated_from', 'fields' => 'ids']));
        self::assertSame(8, $report->counts['create']);
        self::assertSame('2027-01-01', $_POST['qx_a'], 'request data is restored after the write');
    }
}

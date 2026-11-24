<?php

declare(strict_types=1);

namespace EnterOnce\Tests\WordPress;

use EnterOnce\WordPress\EventAdapter;
use EnterOnce\WordPress\Options;
use EnterOnce\WordPress\State;

/** Dry run (R-11, R-31), held runs (R-13), separation of centres (R-04). */
final class SafetyTest extends WpTestCase
{
    public function testDryRunWritesNothing(): void
    {
        $this->configure([], true);
        $snapshot = self::snapshotEvents();
        $report = $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        self::assertTrue($report->dryRun);
        self::assertSame(8, $report->counts['create'], 'all actions are computed');
        self::assertSame($snapshot, self::snapshotEvents(), 'and none is applied');
        self::assertSame([], self::managed());
        $state = State::get('union');
        self::assertTrue($state['dry_run']);
        self::assertCount(8, $state['actions'], 'the planned actions are shown on the status page');
    }

    public function testUnverifiedOrIncompleteMappingForcesDryRun(): void
    {
        $this->configure(['field_map_verified' => false]);
        $snapshot = self::snapshotEvents();
        self::assertTrue($this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00')->dryRun);
        self::assertSame($snapshot, self::snapshotEvents());

        $map = self::standinFieldMap();
        unset($map['vacation_flag']);
        $this->configure(['field_map' => $map]);
        self::assertTrue($this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:15')->dryRun);
        self::assertSame($snapshot, self::snapshotEvents());
    }

    public function testDryRunKeepsMissingCountersUntouched(): void
    {
        $ics = self::fixture('union-beispielkalender.ics');
        $this->sync($ics, '2026-11-19 00:00');
        $brunch = self::byKey('union:union-brunch-1129@example');
        $this->configure([], true);
        $without = (string) preg_replace('/BEGIN:VEVENT\r\nUID:union-brunch-1129@example.*?END:VEVENT\r\n/s', '', $ics);
        $this->sync($without, '2026-11-19 00:15');
        $this->sync($without, '2026-11-19 00:30');
        self::assertSame('publish', get_post_status($brunch));
        self::assertSame('0', get_post_meta($brunch, EventAdapter::META_MISSING, true));
    }

    public function testHeldRunWaitsForAPerson(): void
    {
        $ics = self::fixture('union-beispielkalender.ics');
        $this->sync($ics, '2026-11-19 00:00');
        $without = $ics;
        foreach (['union-mittwochsbar-1125@example', 'union-brunch-1129@example', 'union-theater-1127@example', 'union-repaircafe-1205@example', 'union-seniorensport@example', 'union-kinderpiazza@example'] as $uid) {
            $without = (string) preg_replace('/BEGIN:VEVENT\r\nUID:' . preg_quote($uid, '/') . '.*?END:VEVENT\r\n/s', '', $without);
        }
        $this->sync($without, '2026-11-19 00:15');
        $GLOBALS['enteronce_test_mails'] = [];
        $report = $this->sync($without, '2026-11-19 00:30');
        self::assertTrue($report->held);
        self::assertSame(6, $report->counts['hold']);
        self::assertCount(8, self::managed('union', 'publish'), 'nothing unpublished');
        $state = State::get('union');
        self::assertTrue($state['held']);
        self::assertCount(6, $state['held_actions']);
        $held = array_values(array_filter(self::mails(), static fn ($m) => str_contains($m['subject'], 'angehalten')));
        self::assertCount(1, $held, 'mailed at once');
        self::assertSame(['team@example.org'], $held[0]['to']);
        self::assertStringContainsString('Trotzdem anwenden', $held[0]['message']);

        // Still held on the next run, without a second mail the same day (R-32).
        $GLOBALS['enteronce_test_mails'] = [];
        self::assertTrue($this->sync($without, '2026-11-19 00:45')->held);
        self::assertSame([], array_filter(self::mails(), static fn ($m) => str_contains($m['subject'], 'angehalten')));

        // «Trotzdem anwenden»
        $released = $this->sync($without, '2026-11-19 00:50', 'union', true);
        self::assertFalse($released->held);
        self::assertSame(6, $released->counts['unpublish']);
        self::assertCount(2, self::managed('union', 'publish'));
        self::assertCount(6, self::managed('union', 'draft'));
    }

    public function testOneCentreNeverTouchesAnother(): void
    {
        $this->configure([], false, true);
        $handEntered = [];
        foreach ([$this->seeded['rheinbogen-mittagstisch'], $this->seeded['rheinbogen-flohmarkt'], $this->seeded['kinderpiazza-17'], $this->seeded['deutsch-fuer-den-alltag-2']] as $id) {
            $handEntered[$id] = [get_post($id)->post_modified_gmt, self::allMeta($id)];
        }
        // Rheinbogen syncs its own calendar (the live test calendar stands in for it).
        $this->sync(self::fixture('calendar.ics'), '2027-01-04 00:00', 'rheinbogen');
        $theirs = self::managed('rheinbogen');
        self::assertCount(9, $theirs);
        foreach ($theirs as $id) {
            self::assertSame(['Quartiertreffpunkt Rheinbogen'], wp_get_object_terms($id, 'location_cat', ['fields' => 'names']));
        }
        $theirsBefore = [];
        foreach ($theirs as $id) {
            $theirsBefore[$id] = [get_post_status($id), self::allMeta($id)];
        }

        // Union syncs, then its calendar empties out twice (held), then is released.
        $ics = self::fixture('union-beispielkalender.ics');
        $this->sync($ics, '2027-01-04 00:10');
        $empty = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:only@example.org\r\nSUMMARY:Einzig\r\nDTSTART;TZID=Europe/Zurich:20270120T100000\r\nDTEND;TZID=Europe/Zurich:20270120T110000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->sync($empty, '2027-01-04 00:20');
        $this->sync($empty, '2027-01-04 00:30', 'union', true);

        foreach ($theirsBefore as $id => [$status, $meta]) {
            self::assertSame($status, get_post_status($id));
            self::assertSame($meta, self::allMeta($id), "Rheinbogen record $id untouched");
        }
        foreach ($handEntered as $id => [$modified, $meta]) {
            self::assertSame($modified, get_post($id)->post_modified_gmt, "hand-entered record $id untouched");
            self::assertSame($meta, self::allMeta($id));
        }
        self::assertSame('publish', get_post_status($this->seeded['rheinbogen-flohmarkt']));
    }

    public function testSettingsHaveExactlyThePlanKeys(): void
    {
        $keys = array_keys(Options::defaults());
        self::assertSame([
            'sources', 'sync_interval_minutes', 'stale_after_minutes', 'window_months_ahead', 'removal_grace_runs', 'bulk_unpublish_limit',
            'internal_markers', 'cancel_prefixes', 'cancel_mode', 'weekly_mode', 'weekly_min_dates', 'weekly_lead_days', 'moved_date_note',
            'category_map', 'tag_map', 'synonyms', 'field_map', 'field_map_verified', 'wpml_mode', 'adopt_keep_text',
            'image_mode', 'category_images', 'default_image_id', 'public_feed_enabled', 'feed_allowed_origins', 'feed_cache_seconds',
            'digest_frequency', 'ai_enabled',
        ], $keys);
        self::assertSame(['id', 'label', 'ics_url', 'location_term', 'notify_emails', 'enabled', 'dry_run'], array_keys(Options::sourceDefaults()));
        $stored = get_option(Options::OPTION);
        self::assertSame($keys, array_keys($stored), 'nothing else is stored in enteronce_settings');
        self::assertTrue(Options::sourceDefaults()['dry_run']);
    }
}

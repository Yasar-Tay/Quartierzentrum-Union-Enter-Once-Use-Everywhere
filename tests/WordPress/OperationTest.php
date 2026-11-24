<?php

declare(strict_types=1);

namespace EnterOnce\Tests\WordPress;

use EnterOnce\WordPress\Notifier;
use EnterOnce\WordPress\Options;
use EnterOnce\WordPress\Plugin;
use EnterOnce\WordPress\Scheduler;
use EnterOnce\WordPress\State;

/** R-30 (scheduling, stale warning), R-33 (digest), WPML and cache guards. */
final class OperationTest extends WpTestCase
{
    private function digests(): array
    {
        return array_values(array_filter(self::mails(), static fn ($m) => str_contains($m['subject'], 'Zusammenfassung')));
    }

    public function testDailyDigestAtMostOnceADay(): void
    {
        $ics = self::fixture('union-beispielkalender.ics');
        $this->sync($ics, '2026-11-19 00:00');
        self::assertSame([], $this->digests(), 'daily: not after the first run');
        $this->sync(str_replace('SUMMARY:MittwochsBAR im Union', 'SUMMARY:MittwochsBAR', $ics), '2026-11-19 12:00');
        self::assertSame([], $this->digests());
        $this->sync($ics, '2026-11-20 00:05');
        $digests = $this->digests();
        self::assertCount(1, $digests);
        $body = $digests[0]['message'];
        self::assertSame(['team@example.org'], $digests[0]['to']);
        self::assertSame('Enter Once: Zusammenfassung für Quartierzentrum Union', $digests[0]['subject']);
        foreach (['Neu auf der Website (8)', 'Geändert', 'Warnungen', 'Kulturr', 'Hinweise', 'Theater Niemandsland'] as $part) {
            self::assertStringContainsString($part, $body);
        }
        $this->sync($ics, '2026-11-20 06:00');
        self::assertCount(1, $this->digests(), 'not twice a day');
    }

    public function testDigestEachRunAndMovedDateListed(): void
    {
        $this->configure(['digest_frequency' => 'each_run']);
        $ics = self::fixture('union-beispielkalender.ics');
        $this->sync($ics, '2026-11-19 00:00');
        self::assertCount(1, $this->digests());
        $this->sync($ics, '2026-11-30 10:00');
        $last = $this->digests()[count($this->digests()) - 1]['message'];
        self::assertStringContainsString('Verschobene Termine', $last);
        self::assertStringContainsString('Am 7. Dezember ausnahmsweise 10:00–11:00 Uhr im Raum Grosser Saal', $last, 'R-24: also in the digest');
    }

    public function testStaleWarningAndServerSchedulerPrecedence(): void
    {
        $now = new \DateTimeImmutable('2026-11-19 10:00', new \DateTimeZone('Europe/Zurich'));
        self::assertTrue(Scheduler::isStale([], 60, $now), 'never ran');
        self::assertFalse(Scheduler::isStale(['last_success' => '2026-11-19T09:30:00+01:00'], 60, $now));
        self::assertTrue(Scheduler::isStale(['last_success' => '2026-11-19T08:59:00+01:00'], 60, $now));

        // A run from the server scheduler (WP-CLI) makes the built-in event skip.
        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00', 'union', false, 'cli');
        $last = State::get('union')['last_run'];
        add_filter('enteronce_clock', fn () => $this->clock);
        add_filter('enteronce_fetcher', fn () => $this->fetcher);
        try {
            $this->clock->set('2026-11-19 00:15');
            Scheduler::cronSync();
            self::assertSame($last, State::get('union')['last_run'], 'skipped: the server scheduler is active');
            $this->clock->set('2026-11-19 01:00');
            Scheduler::cronSync();
            self::assertNotSame($last, State::get('union')['last_run'], 'fallback runs when the server scheduler is silent');
            self::assertSame('cron', State::get('union')['trigger']);
        } finally {
            remove_all_filters('enteronce_clock');
            remove_all_filters('enteronce_fetcher');
        }
    }

    public function testCronEventsAreScheduledWithTheInterval(): void
    {
        Plugin::activate();
        self::assertNotFalse(wp_next_scheduled(Scheduler::HOOK_SYNC));
        self::assertNotFalse(wp_next_scheduled(Scheduler::HOOK_DIGEST));
        $schedules = wp_get_schedules();
        self::assertSame(15 * 60, $schedules[Scheduler::SCHEDULE]['interval']);
        Plugin::deactivate();
        self::assertFalse(wp_next_scheduled(Scheduler::HOOK_SYNC));
        // Left unscheduled: the local site's cron must not run syncs during the remaining tests.
    }

    public function testGuardsDoNothingWithoutWpmlOrWpRocket(): void
    {
        self::assertFalse(function_exists('rocket_clean_post'));
        self::assertFalse(\EnterOnce\WordPress\Integrations::wpmlActive());
        self::assertFalse(\EnterOnce\WordPress\Integrations::purgeCache([1, 2]));
        $this->configure(['wpml_mode' => 'duplicate_all']);
        $report = $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        self::assertSame(8, $report->counts['create']);
    }

    public function testPurgeIsTargeted(): void
    {
        $purged = [];
        $listener = static function (array $ids) use (&$purged): void {
            $purged = array_merge($purged, $ids);
        };
        add_action('enteronce_purge_cache', $listener);
        try {
            $ics = self::fixture('union-beispielkalender.ics');
            $this->sync($ics, '2026-11-19 00:00');
            self::assertCount(8, $purged);
            $purged = [];
            $this->sync($ics, '2026-11-19 00:15');
            self::assertSame([], $purged, 'nothing changed, nothing purged');
            $this->sync(str_replace('SUMMARY:Repair Café', 'SUMMARY:Repair Café im Union', $ics), '2026-11-19 00:30');
            self::assertSame([self::byKey('union:union-repaircafe-1205@example')], $purged);
        } finally {
            remove_action('enteronce_purge_cache', $listener);
        }
    }

    public function testDigestRecipientsFallBackToTheSiteAddress(): void
    {
        $settings = Options::get();
        $settings['sources'][0]['notify_emails'] = [];
        Options::save($settings);
        self::assertSame([get_option('admin_email')], Notifier::recipients(Options::get()['sources'][0]));
    }
}

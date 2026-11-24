<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

/**
 * R-30. Two entry points start runs:
 *  - the server's scheduler: `wp enteronce sync` (see docs/dev/wordpress.md for the line);
 *  - WordPress's built-in scheduler as fallback: event `enteronce_sync` every `sync_interval_minutes`.
 * When a server run happened within the last two intervals, the built-in event skips, so the two
 * never double up; a per-source lock prevents overlapping runs in any case.
 */
final class Scheduler
{
    public const HOOK_SYNC = 'enteronce_sync';
    public const HOOK_DIGEST = 'enteronce_digest';
    public const SCHEDULE = 'enteronce_interval';

    public static function register(): void
    {
        add_filter('cron_schedules', static function (array $schedules): array {
            $minutes = max(5, (int) Options::get()['sync_interval_minutes']);
            $schedules[self::SCHEDULE] = [
                'interval' => $minutes * 60,
                'display' => sprintf(__('Enter Once: alle %d Minuten', 'enter-once-sync'), $minutes),
            ];

            return $schedules;
        });
        add_action(self::HOOK_SYNC, [self::class, 'cronSync']);
        add_action(self::HOOK_DIGEST, [self::class, 'cronDigest']);
    }

    public static function schedule(bool $reschedule = false): void
    {
        if ($reschedule) {
            wp_clear_scheduled_hook(self::HOOK_SYNC);
        }
        if (!wp_next_scheduled(self::HOOK_SYNC)) {
            wp_schedule_event(time() + 60, self::SCHEDULE, self::HOOK_SYNC);
        }
        if (!wp_next_scheduled(self::HOOK_DIGEST)) {
            wp_schedule_event(time() + 300, 'hourly', self::HOOK_DIGEST);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK_SYNC);
        wp_clear_scheduled_hook(self::HOOK_DIGEST);
    }

    public static function cronSync(): void
    {
        $plugin = Plugin::instance();
        $now = $plugin->clock()->now();
        $settings = Options::get();
        $interval = max(5, (int) $settings['sync_interval_minutes']) * 60;
        $runner = $plugin->runner();
        foreach ($settings['sources'] as $source) {
            if (empty($source['enabled'])) {
                continue;
            }
            $last = State::get((string) $source['id'])['last_cli_run'] ?? null;
            if (is_string($last) && $now->getTimestamp() - (int) strtotime($last) < 2 * $interval) {
                continue;
            }
            $runner->run((string) $source['id'], 'cron');
        }
    }

    public static function cronDigest(): void
    {
        $notifier = new Notifier(Plugin::instance()->clock());
        foreach (Options::sourceIds(Options::get()) as $id) {
            $notifier->maybeSendDigest($id);
        }
    }

    /**
     * R-30: true when the last successful run is older than `stale_after_minutes`.
     *
     * @param array<string, mixed> $state
     */
    public static function isStale(array $state, int $staleMinutes, \DateTimeImmutable $now): bool
    {
        $last = $state['last_success'] ?? null;
        if (!is_string($last)) {
            return true;
        }

        return $now->getTimestamp() - (int) strtotime($last) > $staleMinutes * 60;
    }
}

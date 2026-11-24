<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

/**
 * Stored per source after every run (options, not autoloaded):
 *  - `enteronce_state_<id>`        status of the last runs (see keys below)
 *  - `enteronce_occurrences_<id>`  the occurrence list for the feed (plan 4.4)
 *
 * State keys: last_run, last_success (ATOM), trigger, dry_run, error (?string), error_time,
 * last_event_count, summary (core), counts (plan), notices (list), actions (compact list of the
 * last plan's changes), held (bool), held_actions (list), weekly_series (list, for adoption),
 * window (array), missing_terms (list), last_cli_run (ATOM).
 */
final class State
{
    public static function key(string $sourceId): string
    {
        return 'enteronce_state_' . $sourceId;
    }

    /** @return array<string, mixed> */
    public static function get(string $sourceId): array
    {
        $s = get_option(self::key($sourceId), []);

        return is_array($s) ? $s : [];
    }

    /** @param array<string, mixed> $changes */
    public static function merge(string $sourceId, array $changes): void
    {
        update_option(self::key($sourceId), array_replace(self::get($sourceId), $changes), false);
    }

    /** @param array<string, mixed> $data ['window' => [...], 'occurrences' => list<array>] */
    public static function storeOccurrences(string $sourceId, array $data): void
    {
        update_option('enteronce_occurrences_' . $sourceId, $data, false);
        update_option('enteronce_feed_version_' . $sourceId, (string) microtime(true), false);
    }

    /** @return array{window?: array{start: string, end: string}, occurrences?: list<array<string, mixed>>} */
    public static function occurrences(string $sourceId): array
    {
        $o = get_option('enteronce_occurrences_' . $sourceId, []);

        return is_array($o) ? $o : [];
    }

    public static function feedVersion(string $sourceId): string
    {
        return (string) get_option('enteronce_feed_version_' . $sourceId, '0');
    }

    /** Removes everything the plugin stored for a source (not the records). */
    public static function forget(string $sourceId): void
    {
        foreach (['enteronce_state_', 'enteronce_occurrences_', 'enteronce_feed_version_', 'enteronce_digest_', 'enteronce_lock_'] as $prefix) {
            delete_option($prefix . $sourceId);
        }
    }
}

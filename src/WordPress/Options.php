<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

use EnterOnce\Core\Settings as CoreSettings;

/**
 * The one option `enteronce_settings` (plan sections 4.3 and 6.6): exactly the keys of 6.6.
 * Per-run state lives in separate options (see {@see State}); nothing else is stored here.
 */
final class Options
{
    public const OPTION = 'enteronce_settings';

    /** @return array<string, mixed> all keys of plan section 6.6 with their defaults */
    public static function defaults(): array
    {
        $core = CoreSettings::defaults();

        return [
            'sources' => [],
            'sync_interval_minutes' => 15,
            'stale_after_minutes' => 60,
            'window_months_ahead' => 3,
            'removal_grace_runs' => 2,
            'bulk_unpublish_limit' => 5,
            'internal_markers' => ['class_not_public' => true, 'categories' => ['Intern']],
            'cancel_prefixes' => ['Abgesagt:', 'Canceled:', 'Cancelled:'],
            'cancel_mode' => 'unpublish',
            'weekly_mode' => 'weekly_offer',
            'weekly_min_dates' => 4,
            'weekly_lead_days' => 14,
            'moved_date_note' => true,
            'category_map' => $core['category_map'],
            'tag_map' => $core['tag_map'],
            'synonyms' => $core['synonyms'],
            'field_map' => [],
            'field_map_verified' => false,
            'wpml_mode' => 'default_language',
            'adopt_keep_text' => true,
            'image_mode' => 'media_library',
            'category_images' => [],
            'default_image_id' => 0,
            'public_feed_enabled' => true,
            'feed_allowed_origins' => ['*'],
            'feed_cache_seconds' => 300,
            'digest_frequency' => 'daily',
            'ai_enabled' => false,
        ];
    }

    /** @return array<string, mixed> one `sources[]` entry with defaults */
    public static function sourceDefaults(): array
    {
        return [
            'id' => '',
            'label' => '',
            'ics_url' => '',
            'location_term' => 0,
            'notify_emails' => [],
            'enabled' => true,
            'dry_run' => true,
        ];
    }

    /** @return array<string, mixed> stored settings merged with defaults */
    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);

        return self::withDefaults(is_array($stored) ? $stored : []);
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public static function withDefaults(array $stored): array
    {
        $defaults = self::defaults();
        $out = [];
        foreach ($defaults as $key => $default) {
            $out[$key] = array_key_exists($key, $stored) ? $stored[$key] : $default;
        }
        $sources = [];
        foreach ((array) $out['sources'] as $source) {
            if (is_array($source) && ($source['id'] ?? '') !== '') {
                $sources[] = array_replace(self::sourceDefaults(), array_intersect_key($source, self::sourceDefaults()));
            }
        }
        $out['sources'] = $sources;

        return $out;
    }

    /** @param array<string, mixed> $settings */
    public static function save(array $settings): void
    {
        update_option(self::OPTION, self::withDefaults($settings), false);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>|null
     */
    public static function source(array $settings, string $id): ?array
    {
        foreach ($settings['sources'] as $source) {
            if ($source['id'] === $id) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    public static function sourceIds(array $settings): array
    {
        return array_values(array_map(static fn (array $s) => (string) $s['id'], $settings['sources']));
    }

    /** Field map as a {@see FieldMap}. @param array<string, mixed> $settings */
    public static function fieldMap(array $settings): FieldMap
    {
        return new FieldMap(is_array($settings['field_map']) ? $settings['field_map'] : []);
    }
}

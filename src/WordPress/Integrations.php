<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

/**
 * Guarded integrations with other plugins. Each does nothing when the plugin is absent.
 *  - R-09: WP Rocket page cache, purged only for changed records and the programme page.
 *  - A-04: WPML, records created in the default language, or duplicated to all languages.
 */
final class Integrations
{
    public const PROGRAMME_PATH = '/programm/';

    /**
     * @param list<int> $postIds records changed by the run
     * @return bool whether a cache plugin was found
     */
    public static function purgeCache(array $postIds): bool
    {
        $postIds = array_values(array_unique(array_filter($postIds)));
        if ($postIds === []) {
            return false;
        }
        /** Lets other cache plugins hook in. */
        do_action('enteronce_purge_cache', $postIds);
        if (!function_exists('rocket_clean_post')) {
            return false;
        }
        foreach ($postIds as $id) {
            rocket_clean_post($id);
        }
        if (function_exists('rocket_clean_files')) {
            rocket_clean_files([home_url(self::PROGRAMME_PATH)]);
        }

        return true;
    }

    public static function wpmlActive(): bool
    {
        return defined('ICL_SITEPRESS_VERSION') && has_filter('wpml_default_language');
    }

    /** A-04, after a record was created. */
    public static function afterCreate(int $postId, string $mode): void
    {
        if (!self::wpmlActive()) {
            return;
        }
        $default = apply_filters('wpml_default_language', null);
        if (is_string($default) && $default !== '') {
            do_action('wpml_set_element_language_details', [
                'element_id' => $postId,
                'element_type' => 'post_' . EventAdapter::POST_TYPE,
                'trid' => false,
                'language_code' => $default,
            ]);
        }
        if ($mode === 'duplicate_all') {
            do_action('wpml_admin_make_post_duplicates', $postId);
        }
    }

    /** A-04, after a record was updated. */
    public static function afterUpdate(int $postId, string $mode): void
    {
        if (self::wpmlActive() && $mode === 'duplicate_all') {
            do_action('wpml_admin_make_post_duplicates', $postId);
        }
    }
}

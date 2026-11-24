<?php
/**
 * Runs when the plugin is deleted in «Plugins» (review D9).
 *
 * Removes everything the plugin stored in options and transients, including the secret
 * calendar addresses, and its scheduled events. The event records on the site and their
 * `_enteronce_*` meta are kept: they are the association's programme. (Without the plugin they
 * are ordinary records; a later reinstallation recognises them again by that meta.)
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$enteronceDelete = static function (): void {
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE 'enteronce\\_%'
            OR option_name LIKE '\\_transient\\_enteronce\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_enteronce\\_%'"
    );
    wp_clear_scheduled_hook('enteronce_sync');
    wp_clear_scheduled_hook('enteronce_digest');
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $enteronceSite) {
        switch_to_blog((int) $enteronceSite);
        $enteronceDelete();
        restore_current_blog();
    }
} else {
    $enteronceDelete();
}
wp_cache_flush();

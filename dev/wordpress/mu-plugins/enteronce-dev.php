<?php
/**
 * Plugin Name: Enter Once – local development helpers
 * Description: Only on the local test site. Captures outgoing e-mail (nothing leaves the machine) and
 * lets the source address https://fixtures.enteronce.invalid/<file>.ics read tests/fixtures/<file>.ics,
 * so «Jetzt abgleichen» works offline. Never ship this file.
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH') || wp_get_environment_type() !== 'local') {
    return;
}

// Every e-mail is stored in an option instead of being sent (last 50).
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $log = get_option('enteronce_dev_mails', []);
    $log = is_array($log) ? $log : [];
    $log[] = ['to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message'], 'time' => gmdate('c')];
    update_option('enteronce_dev_mails', array_slice($log, -50), false);

    return true;
}, 10, 2);

// Offline calendar addresses for the demo.
add_filter('enteronce_fetcher', static function ($fetcher) {
    if (!interface_exists(\EnterOnce\WordPress\Fetcher::class)) {
        return $fetcher;
    }

    return new class ($fetcher) implements \EnterOnce\WordPress\Fetcher {
        public function __construct(private \EnterOnce\WordPress\Fetcher $inner)
        {
        }

        public function fetch(string $url): string
        {
            if (preg_match('#^https://fixtures\.enteronce\.invalid/([a-z0-9\-]+\.ics)$#', $url, $m)) {
                $path = WP_PLUGIN_DIR . '/enter-once-sync/tests/fixtures/' . $m[1];
                if (is_file($path)) {
                    return (string) file_get_contents($path);
                }
            }

            return $this->inner->fetch($url);
        }
    };
});

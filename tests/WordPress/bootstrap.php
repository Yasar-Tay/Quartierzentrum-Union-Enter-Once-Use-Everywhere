<?php

declare(strict_types=1);

/*
 * Loads the local test site's WordPress (docker-compose.yml). The tests run against its database:
 * they delete all events and the plugin's options. Run dev/wordpress/seed afterwards for demo data.
 */

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost:18471';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
define('WP_USE_THEMES', false);
define('ENTERONCE_TESTING', true);

$wpLoad = '/var/www/html/wp-load.php';
if (!is_file($wpLoad)) {
    fwrite(STDERR, "WordPress not found at $wpLoad. Run the tests with dev/wordpress/test.\n");
    exit(1);
}
require $wpLoad;

if (!function_exists('qtp_standin_seed')) {
    fwrite(STDERR, "The stand-in theme qtp-standin is not active. Run dev/wordpress/start.\n");
    exit(1);
}

// The local site's own WordPress cron must not start real runs while tests change the settings
// (a web request would otherwise run the plugin's sync in parallel and hold the source lock).
\EnterOnce\WordPress\Scheduler::unschedule();
delete_transient('doing_cron');

// No e-mail leaves the container; tests read them from here.
$GLOBALS['enteronce_test_mails'] = [];
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $GLOBALS['enteronce_test_mails'][] = $atts;

    return true;
}, 1, 2);

// No request leaves the container during tests.
add_filter('pre_http_request', static function ($pre, array $args, string $url) {
    if ($pre !== false) {
        return $pre; // a test transport answered
    }
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    if (!in_array($host, ['localhost', 'wordpress', '127.0.0.1'], true)) {
        throw new RuntimeException('Network request attempted during tests: ' . $host);
    }

    return $pre;
}, 1, 3);

require __DIR__ . '/WpTestCase.php';

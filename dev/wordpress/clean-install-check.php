<?php
// Checks on the clean site after activating the zip (run with wp eval-file).
$fail = static function (string $m): void {
    fwrite(STDERR, "FAIL: $m\n");
    exit(1);
};
if (!class_exists(\EnterOnce\WordPress\Plugin::class)) {
    $fail('plugin classes not loaded');
}
if (!class_exists(\EnterOnce\Vendor\Sabre\VObject\Reader::class)) {
    $fail('prefixed sabre/vobject not loadable');
}
$settings = get_option('enteronce_settings');
if (!is_array($settings) || count($settings) !== 28) {
    $fail('settings option not created with the 28 keys of plan 6.6');
}
if (!wp_next_scheduled('enteronce_sync')) {
    $fail('sync not scheduled');
}
$routes = rest_get_server()->get_routes();
if (!isset($routes['/enteronce/v1/events'])) {
    $fail('feed route missing');
}
$core = new \EnterOnce\Core\Core([], 'union');
$r = $core->read((string) file_get_contents('/var/www/html/wp-content/plugins/enter-once-sync/tests/fixtures/union-beispielkalender.ics'), new DateTimeImmutable('2026-11-19 00:00', new DateTimeZone('Europe/Zurich')), \EnterOnce\Core\Window::fromDates('2026-11-19', '2027-03-31'));
if (count($r->activeOccurrences()) !== 71) {
    $fail('core in the installed zip does not read the sample to 71');
}
// Files that must ship with the plugin: readme, licence, every German guide of the project.
$installed = WP_PLUGIN_DIR . '/enter-once-sync';
foreach (['FixedClock', 'StaticFetcher'] as $double) {
    if (class_exists('EnterOnce\\WordPress\\' . $double) || is_file(WP_PLUGIN_DIR . '/enter-once-sync/src/WordPress/' . $double . '.php')) {
        $fail("test double $double ships in the zip");
    }
}
if (defined('ENTERONCE_DEV')) {
    $fail('ENTERONCE_DEV is defined on the clean site');
}
$required = ['readme.txt', 'LICENSE', 'uninstall.php', 'widget/enteronce-widget.js', 'widget/enteronce-widget.css'];
foreach (glob('/var/www/html/wp-content/plugins/enter-once-sync/docs/de/*.md') ?: [] as $guide) {
    $required[] = 'docs/de/' . basename($guide);
}
if (count($required) < 6) {
    $fail('no German guides found in the project');
}
foreach ($required as $file) {
    if (!is_file($installed . '/' . $file) || filesize($installed . '/' . $file) === 0) {
        $fail("$file missing from the installed plugin");
    }
}
if (!str_contains((string) file_get_contents($installed . '/LICENSE'), 'GNU GENERAL PUBLIC LICENSE') || !str_contains((string) file_get_contents($installed . '/LICENSE'), 'Version 2, June 1991')) {
    $fail('LICENSE is not the GPL version 2 text');
}
echo "Installed plugin: classes, prefixed library, 28 settings keys, cron event, feed route, 71 occurrences, "
    . count($required) . " required files (readme.txt, LICENSE, uninstall.php, widget, " . (count($required) - 5) . " guides): OK\n";

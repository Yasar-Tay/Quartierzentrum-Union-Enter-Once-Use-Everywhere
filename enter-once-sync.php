<?php
/**
 * Plugin Name:       Enter Once Sync
 * Description:       Einmal erfassen, überall anzeigen: übernimmt die öffentlichen Veranstaltungen eines Zentrums aus einem veröffentlichten Kalender (ICS) in das Veranstaltungs-Tool und bietet sie als öffentlichen Feed an.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Freiwilliges Projekt für das Quartierzentrum Union
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       enter-once-sync
 * Domain Path:       /languages
 *
 * Ein Geschenk an das Quartierzentrum Union und den Verband Quartiertreffpunkte Basel.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

if (PHP_VERSION_ID < 80100) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>' . esc_html__('Enter Once Sync braucht PHP 8.1 oder neuer.', 'enter-once-sync') . '</p></div>';
    });

    return;
}

if (!class_exists(\EnterOnce\WordPress\Plugin::class)) {
    $enteronceAutoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($enteronceAutoload)) {
        add_action('admin_notices', static function (): void {
            echo '<div class="notice notice-error"><p>' . esc_html__('Enter Once Sync ist unvollständig installiert (vendor/autoload.php fehlt).', 'enter-once-sync') . '</p></div>';
        });

        return;
    }
    require_once $enteronceAutoload;
}

define('ENTERONCE_PLUGIN_FILE', __FILE__);
define('ENTERONCE_VERSION', '0.1.0');

register_activation_hook(__FILE__, [\EnterOnce\WordPress\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\EnterOnce\WordPress\Plugin::class, 'deactivate']);

\EnterOnce\WordPress\Plugin::instance()->boot();

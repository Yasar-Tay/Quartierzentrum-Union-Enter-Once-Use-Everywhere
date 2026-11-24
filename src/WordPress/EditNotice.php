<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

/** Plan 4.7: notice on the edit screen of managed records. */
final class EditNotice
{
    public static function register(): void
    {
        add_action('admin_notices', [self::class, 'render']);
    }

    public static function render(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->base !== 'post' || $screen->post_type !== EventAdapter::POST_TYPE) {
            return;
        }
        $postId = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if ($postId === 0 || get_post_meta($postId, EventAdapter::META_MANAGED, true) !== '1') {
            return;
        }
        echo '<div class="notice notice-info"><p>' . esc_html(self::message(get_post_meta($postId, EventAdapter::META_KEEP_TEXT, true) === '1')) . '</p></div>';
    }

    public static function message(bool $keepText): string
    {
        return $keepText
            ? __('Dieser Eintrag kommt aus dem Kalender (Enter Once). Datum, Zeiten und Status werden beim nächsten Abgleich überschrieben, Themen und Zielgruppen nur, wenn der Kalender welche angibt. Titel und Text der Website bleiben erhalten.', 'enter-once-sync')
            : __('Dieser Eintrag kommt aus dem Kalender (Enter Once). Änderungen hier werden beim nächsten Abgleich überschrieben. Ändern Sie die Veranstaltung im Kalender. Ein hier gesetztes Beitragsbild bleibt erhalten.', 'enter-once-sync');
    }
}

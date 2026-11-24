<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

use EnterOnce\Core\GermanDate;

/**
 * «Einstellungen → Enter Once»: status page, settings, field-mapping wizard, adoption.
 * Administrators only (`manage_options`); every action is a POST with a nonce.
 */
final class Admin
{
    public const SLUG = 'enter-once';
    public const CAP = 'manage_options';
    private const ACTIONS = ['save_settings', 'sync_now', 'release_held', 'resolve_terms', 'wizard', 'adopt', 'undo_adoption'];

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_options_page(__('Enter Once', 'enter-once-sync'), __('Enter Once', 'enter-once-sync'), self::CAP, self::SLUG, [$this, 'render']);
        });
        foreach (self::ACTIONS as $action) {
            add_action('admin_post_enteronce_' . $action, [$this, 'handle' . str_replace('_', '', ucwords($action, '_'))]);
        }
        add_filter('plugin_action_links_' . plugin_basename(ENTERONCE_PLUGIN_FILE), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(self::url('settings')) . '">' . esc_html__('Einstellungen', 'enter-once-sync') . '</a>');

            return $links;
        });
    }

    public static function url(string $tab = 'status', array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG, 'tab' => $tab], $args), admin_url('options-general.php'));
    }

    /* ================================================================ handlers */

    private function guard(string $action): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Sie haben keine Berechtigung für diese Seite.', 'enter-once-sync'), '', ['response' => 403]);
        }
        check_admin_referer('enteronce_' . $action);
    }

    private function back(string $tab, array $args = []): void
    {
        wp_safe_redirect(self::url($tab, $args));
        exit;
    }

    public static function flash(string $type, string $message): void
    {
        $key = 'enteronce_flash_' . get_current_user_id();
        $list = get_transient($key);
        $list = is_array($list) ? $list : [];
        $list[] = [$type, $message];
        set_transient($key, $list, 300);
    }

    public function handleSaveSettings(): void
    {
        $this->guard('save_settings');
        $input = isset($_POST['enteronce']) && is_array($_POST['enteronce']) ? wp_unslash($_POST['enteronce']) : [];
        $old = Options::get();
        [$settings, $errors] = self::sanitize($input, $old);
        Options::save($settings);
        $missing = (new TermResolver())->resolveAll($settings);
        if ((int) $old['sync_interval_minutes'] !== (int) $settings['sync_interval_minutes']) {
            Scheduler::schedule(true);
        }
        foreach ($errors as $e) {
            self::flash('error', $e);
        }
        foreach ($missing as $m) {
            self::flash('warning', sprintf(__('Begriff nicht gefunden: %s. Legen Sie ihn auf der Website an oder ändern Sie die Zuordnung.', 'enter-once-sync'), $m));
        }
        self::flash('success', __('Einstellungen gespeichert.', 'enter-once-sync'));
        $this->back('settings');
    }

    public function handleSyncNow(): void
    {
        $this->guard('sync_now');
        $source = sanitize_key((string) ($_POST['source'] ?? ''));
        $report = Plugin::instance()->runner()->run($source, 'manual');
        self::flashReport($report);
        $this->back('status');
    }

    /** «Trotzdem anwenden»: applies the held list only if it is still the list the administrator saw. */
    public function handleReleaseHeld(): void
    {
        $this->guard('release_held');
        $source = sanitize_key((string) ($_POST['source'] ?? ''));
        $hash = sanitize_key((string) ($_POST['held_hash'] ?? ''));
        $report = Plugin::instance()->runner()->run($source, 'manual', false, $hash);
        self::flashReport($report);
        $this->back('status');
    }

    public function handleResolveTerms(): void
    {
        $this->guard('resolve_terms');
        $missing = (new TermResolver())->resolveAll(Options::get());
        self::flash($missing === [] ? 'success' : 'warning', $missing === []
            ? __('Alle Begriffe wurden gefunden.', 'enter-once-sync')
            : __('Nicht gefunden:', 'enter-once-sync') . ' ' . implode('; ', $missing));
        $this->back('settings');
    }

    public function handleWizard(): void
    {
        $this->guard('wizard');
        $wizard = new FieldWizard();
        $op = sanitize_key((string) ($_POST['op'] ?? ''));
        match ($op) {
            'begin' => $wizard->begin(),
            'compare' => $wizard->compare(),
            'skip' => $wizard->skip(),
            'resnapshot' => $wizard->resnapshot(),
            'testdraft' => $wizard->writeTestDraft(),
            'confirm' => $wizard->confirm(),
            'reset' => FieldWizard::reset(),
            default => null,
        };
        $this->back('mapping');
    }

    /**
     * Adoption (review D6): from an offer's card only «Vergleich anzeigen» is possible; «Übernehmen»
     * exists only in the comparison view, which also says what happens to the offer's status.
     */
    public function handleAdopt(): void
    {
        $this->guard('adopt');
        $source = sanitize_key((string) ($_POST['source'] ?? ''));
        $postId = absint($_POST['post_id'] ?? 0);
        $series = sanitize_text_field((string) wp_unslash($_POST['series'] ?? ''));
        $keep = !empty($_POST['keep_text']);
        $op = sanitize_key((string) ($_POST['op'] ?? 'compare'));
        if ($series === '' || $series === 'none') {
            self::flash('info', __('Nicht übernommen. Dieses Angebot bleibt unverändert.', 'enter-once-sync'));
            $this->back('adopt', ['source' => $source]);
        }
        if ($op !== 'confirm_adopt') {
            $this->back('adopt', ['source' => $source, 'compare' => $postId, 'series' => rawurlencode($series), 'keep' => $keep ? 1 : 0]);
        }
        $publish = !empty($_POST['publish']);
        $settings = Options::get();
        $result = (new Adoption($settings, Options::fieldMap($settings)))->adopt($postId, $source, $series, $keep, Plugin::instance()->clock()->now(), $publish);
        if (is_wp_error($result)) {
            self::flash('error', $result->get_error_message());
        } else {
            self::flash('success', sprintf(__('«%s» übernommen. Vor dem ersten Schreiben wurde eine Kopie (Revision) gespeichert. Der nächste Abgleich übernimmt Zeit, Status und Themen.', 'enter-once-sync'), get_the_title($postId)));
        }
        $this->back('adopt', ['source' => $source]);
    }

    /** «Übernahme rückgängig machen» (review D5). */
    public function handleUndoAdoption(): void
    {
        $this->guard('undo_adoption');
        $source = sanitize_key((string) ($_POST['source'] ?? ''));
        $postId = absint($_POST['post_id'] ?? 0);
        $result = Adoption::undo($postId);
        if (is_wp_error($result)) {
            self::flash('error', $result->get_error_message());
        } else {
            self::flash('success', sprintf(__('Übernahme von «%s» rückgängig gemacht: Titel, Text, Status, Felder und Begriffe sind wieder wie vorher. Der Abgleich verändert dieses Angebot nicht mehr.', 'enter-once-sync'), get_the_title($postId)));
        }
        $this->back('adopt', ['source' => $source]);
    }

    private static function flashReport(RunReport $r): void
    {
        if ($r->status === RunReport::OK) {
            $c = $r->counts;
            self::flash($r->held ? 'warning' : 'success', sprintf(
                $r->dryRun
                    ? __('Probelauf abgeschlossen: %1$d neu, %2$d geändert, %3$d auf Entwurf, %4$d angehalten (nichts geschrieben).', 'enter-once-sync')
                    : __('Abgleich abgeschlossen: %1$d neu, %2$d geändert, %3$d auf Entwurf, %4$d angehalten.', 'enter-once-sync'),
                $c['create'] ?? 0,
                $c['update'] ?? 0,
                $c['unpublish'] ?? 0,
                $c['hold'] ?? 0
            ));
            if ($r->error !== null) {
                self::flash('error', $r->error);
            }
            if ($r->remaining > 0) {
                self::flash('info', sprintf(__('%d Änderungen folgen beim nächsten Abgleich (Zeitbudget erreicht).', 'enter-once-sync'), $r->remaining));
            }
        } else {
            self::flash('error', (string) ($r->error ?? __('Abgleich nicht ausgeführt.', 'enter-once-sync')));
        }
    }

    /** Server scheduler line for `sync_interval_minutes` (review: was fixed to every 15 minutes). */
    public static function cronLine(int $minutes, string $path): string
    {
        $minutes = max(5, $minutes);
        $schedule = match (true) {
            $minutes < 60 => '*/' . $minutes . ' * * * *',
            $minutes % 1440 === 0 => '0 0 */' . intdiv($minutes, 1440) . ' * *',
            $minutes % 60 === 0 => '0 */' . intdiv($minutes, 60) . ' * * *',
            default => '*/' . min(59, $minutes) . ' * * * *',
        };

        return $schedule . ' wp --path=' . $path . ' enteronce sync --quiet';
    }

    /** Feed address of a source, correct with plain and pretty permalinks. */
    public static function feedUrl(string $sourceId): string
    {
        return add_query_arg('source', rawurlencode($sourceId), rest_url(FeedController::NAMESPACE . FeedController::ROUTE));
    }

    /* ================================================================ sanitising */

    /**
     * Settings form → settings array (keys of plan 6.6 only).
     *
     * @param array<string, mixed> $in
     * @param array<string, mixed> $current
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function sanitize(array $in, array $current): array
    {
        $errors = [];
        $s = $current;
        $int = static fn ($v, int $min, int $max) => max($min, min($max, (int) $v));
        $lines = static fn ($v): array => array_values(array_filter(array_map('trim', preg_split('/\R|,/', (string) $v) ?: []), static fn ($x) => $x !== ''));

        // Centres.
        $sources = [];
        $seen = [];
        foreach ((array) ($in['sources'] ?? []) as $row) {
            if (!is_array($row) || !empty($row['remove'])) {
                continue;
            }
            $id = sanitize_key((string) ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if (isset($seen[$id])) {
                $errors[] = sprintf(__('Die Kennung «%s» kommt zweimal vor.', 'enter-once-sync'), $id);
                continue;
            }
            $seen[$id] = true;
            $previous = Options::source($current, $id);
            $url = trim((string) ($row['ics_url'] ?? ''));
            if ($url === '' && empty($row['ics_url_clear'])) {
                $url = (string) ($previous['ics_url'] ?? '');
            } elseif ($url !== '') {
                $url = esc_url_raw($url, ['https']);
                if ($url === '' || strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                    $errors[] = sprintf(__('«%s»: Die Kalender-Adresse (ICS) muss mit https:// beginnen. Die bisherige Adresse bleibt.', 'enter-once-sync'), $id);
                    $url = (string) ($previous['ics_url'] ?? '');
                }
            }
            $emails = [];
            foreach ($lines($row['notify_emails'] ?? '') as $e) {
                $e = sanitize_email($e);
                if (is_email($e)) {
                    $emails[] = $e;
                } else {
                    $errors[] = sprintf(__('«%s»: ungültige E-Mail-Adresse wurde entfernt.', 'enter-once-sync'), $id);
                }
            }
            $sources[] = [
                'id' => $id,
                'label' => sanitize_text_field((string) ($row['label'] ?? '')),
                'ics_url' => $url,
                'location_term' => absint($row['location_term'] ?? 0),
                'notify_emails' => array_values(array_unique($emails)),
                'enabled' => !empty($row['enabled']),
                'dry_run' => !empty($row['dry_run']),
            ];
        }
        $s['sources'] = $sources;

        $s['sync_interval_minutes'] = $int($in['sync_interval_minutes'] ?? 15, 5, 1440);
        $s['stale_after_minutes'] = $int($in['stale_after_minutes'] ?? 60, 10, 10080);
        $s['window_months_ahead'] = $int($in['window_months_ahead'] ?? 3, 1, 24);
        $s['removal_grace_runs'] = $int($in['removal_grace_runs'] ?? 2, 1, 50);
        $s['bulk_unpublish_limit'] = $int($in['bulk_unpublish_limit'] ?? 5, 0, 1000);
        $s['internal_markers'] = [
            'class_not_public' => !empty($in['internal_class_not_public']),
            'categories' => array_map('sanitize_text_field', $lines($in['internal_categories'] ?? '')),
        ];
        $s['cancel_prefixes'] = array_map('sanitize_text_field', array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($in['cancel_prefixes'] ?? '')) ?: []))));
        $s['cancel_mode'] = ($in['cancel_mode'] ?? '') === 'badge' ? 'badge' : 'unpublish';
        $s['weekly_mode'] = ($in['weekly_mode'] ?? '') === 'per_date' ? 'per_date' : 'weekly_offer';
        $s['weekly_min_dates'] = $int($in['weekly_min_dates'] ?? 4, 1, 100);
        $s['weekly_lead_days'] = $int($in['weekly_lead_days'] ?? 14, 0, 365);
        $s['moved_date_note'] = !empty($in['moved_date_note']);
        $s['category_map'] = self::parseMap((string) ($in['category_map'] ?? ''));
        $s['tag_map'] = self::parseMap((string) ($in['tag_map'] ?? ''));
        $synonyms = [];
        foreach (preg_split('/\R/', (string) ($in['synonyms'] ?? '')) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$a, $b] = array_map('trim', explode('=', $line, 2));
                if ($a !== '' && $b !== '') {
                    $synonyms[sanitize_text_field($a)] = sanitize_text_field($b);
                }
            }
        }
        $s['synonyms'] = $synonyms;
        $s['wpml_mode'] = ($in['wpml_mode'] ?? '') === 'duplicate_all' ? 'duplicate_all' : 'default_language';
        $s['adopt_keep_text'] = !empty($in['adopt_keep_text']);
        $s['image_mode'] = 'media_library';
        $images = [];
        foreach (preg_split('/\R/', (string) ($in['category_images'] ?? '')) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$cat, $id] = array_map('trim', explode('=', $line, 2));
                if ($cat !== '' && absint($id) > 0) {
                    $images[sanitize_text_field($cat)] = absint($id);
                }
            }
        }
        $s['category_images'] = $images;
        $s['default_image_id'] = absint($in['default_image_id'] ?? 0);
        $s['public_feed_enabled'] = !empty($in['public_feed_enabled']);
        $origins = [];
        foreach ($lines($in['feed_allowed_origins'] ?? '*') as $o) {
            $origins[] = $o === '*' ? '*' : untrailingslashit(esc_url_raw($o, ['https', 'http']));
        }
        $s['feed_allowed_origins'] = array_values(array_filter($origins)) ?: ['*'];
        $s['feed_cache_seconds'] = $int($in['feed_cache_seconds'] ?? 300, 0, 86400);
        $s['digest_frequency'] = in_array($in['digest_frequency'] ?? '', ['daily', 'each_run', 'weekly'], true) ? (string) $in['digest_frequency'] : 'daily';
        $s['ai_enabled'] = !empty($in['ai_enabled']);
        // field_map and field_map_verified are changed only by the wizard.

        return [Options::withDefaults($s), $errors];
    }

    /**
     * «Kalenderwert = Begriff; Begriff» per line; «Kalenderwert = –» means not transferred.
     *
     * @return array<string, list<string>|null>
     */
    public static function parseMap(string $text): array
    {
        $map = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $key = sanitize_text_field($key);
            if ($key === '') {
                continue;
            }
            $targets = array_values(array_filter(array_map(static fn ($t) => sanitize_text_field(trim($t)), explode(';', $value)), static fn ($t) => $t !== '' && $t !== '–' && $t !== '-'));
            $map[$key] = $targets === [] ? null : $targets;
        }

        return $map;
    }

    /** @param array<string, mixed> $map */
    public static function formatMap(array $map): string
    {
        $lines = [];
        foreach ($map as $key => $targets) {
            $lines[] = $key . ' = ' . ($targets === null || $targets === [] ? '–' : implode('; ', (array) $targets));
        }

        return implode("\n", $lines);
    }

    /* ================================================================ rendering */

    public function render(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Sie haben keine Berechtigung für diese Seite.', 'enter-once-sync'), '', ['response' => 403]);
        }
        $tab = sanitize_key((string) ($_GET['tab'] ?? 'status'));
        $tabs = [
            'status' => __('Status', 'enter-once-sync'),
            'settings' => __('Einstellungen', 'enter-once-sync'),
            'mapping' => __('Feldzuordnung', 'enter-once-sync'),
            'adopt' => __('Bestehende Angebote übernehmen', 'enter-once-sync'),
        ];
        if (!isset($tabs[$tab])) {
            $tab = 'status';
        }
        echo '<div class="wrap"><h1>' . esc_html__('Enter Once', 'enter-once-sync') . '</h1>';
        $flash = get_transient('enteronce_flash_' . get_current_user_id());
        if (is_array($flash)) {
            delete_transient('enteronce_flash_' . get_current_user_id());
            foreach ($flash as [$type, $message]) {
                echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
            }
        }
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            echo '<a class="nav-tab' . ($key === $tab ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url($key)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        match ($tab) {
            'settings' => $this->renderSettings(),
            'mapping' => $this->renderMapping(),
            'adopt' => $this->renderAdoption(),
            default => $this->renderStatus(),
        };
        echo '</div>';
    }

    private static function button(string $action, string $label, array $fields = [], string $class = 'button'): string
    {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 .5em .5em 0">';
        $html .= '<input type="hidden" name="action" value="enteronce_' . esc_attr($action) . '">';
        $html .= wp_nonce_field('enteronce_' . $action, '_wpnonce', true, false);
        foreach ($fields as $k => $v) {
            $html .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
        }

        return $html . '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    private static function typeLabel(string $type): string
    {
        return match ($type) {
            'create' => __('Neu', 'enter-once-sync'),
            'update' => __('Ändern', 'enter-once-sync'),
            'unpublish' => __('Auf Entwurf setzen', 'enter-once-sync'),
            'hold' => __('Angehalten', 'enter-once-sync'),
            default => __('Unverändert', 'enter-once-sync'),
        };
    }

    private static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'new' => __('neu im Kalender', 'enter-once-sync'),
            'changed' => __('im Kalender geändert', 'enter-once-sync'),
            'republish' => __('wieder im Kalender', 'enter-once-sync'),
            'reuse_draft' => __('neue Serie, bestehender Entwurf wird weiterverwendet', 'enter-once-sync'),
            'missing' => __('fehlt im Kalender', 'enter-once-sync'),
            'cancelled' => __('abgesagt', 'enter-once-sync'),
            'series_ended' => __('Serie beendet', 'enter-once-sync'),
            'missing_grace' => __('fehlt im Kalender (Sicherheitsabstand)', 'enter-once-sync'),
            default => $reason,
        };
    }

    private function renderStatus(): void
    {
        $settings = Options::get();
        $now = Plugin::instance()->clock()->now();
        $map = Options::fieldMap($settings);
        if (!$settings['field_map_verified'] || !$map->isComplete()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Die Feldzuordnung ist noch nicht geprüft. Bis dahin läuft jeder Abgleich als Probelauf und schreibt nichts.', 'enter-once-sync') . ' <a href="' . esc_url(self::url('mapping')) . '">' . esc_html__('Zur Feldzuordnung', 'enter-once-sync') . '</a></p></div>';
        }
        if ($settings['sources'] === []) {
            echo '<p>' . esc_html__('Noch kein Zentrum eingerichtet.', 'enter-once-sync') . ' <a href="' . esc_url(self::url('settings')) . '">' . esc_html__('Zu den Einstellungen', 'enter-once-sync') . '</a></p>';
        }
        foreach ($settings['sources'] as $source) {
            $id = (string) $source['id'];
            $st = State::get($id);
            echo '<h2>' . esc_html(Notifier::label($source)) . '</h2>';
            if (Scheduler::isStale($st, (int) $settings['stale_after_minutes'], $now) && !empty($source['enabled'])) {
                echo '<div class="notice notice-error inline"><p>' . esc_html(sprintf(__('Veraltet: seit mehr als %d Minuten kein erfolgreicher Abgleich.', 'enter-once-sync'), (int) $settings['stale_after_minutes'])) . '</p></div>';
            }
            if (!empty($st['error'])) {
                echo '<div class="notice notice-error inline"><p>' . esc_html__('Letzter Fehler:', 'enter-once-sync') . ' ' . esc_html((string) $st['error']) . '</p></div>';
            }
            $term = get_term((int) $source['location_term'], TermResolver::TAX_LOCATION);
            $dry = !empty($source['dry_run']) || !$settings['field_map_verified'] || !$map->isComplete();
            $rows = [
                __('Kalender-Adresse (ICS)', 'enter-once-sync') => Secret::mask((string) $source['ics_url']),
                __('Zentrum auf der Verbands-Website', 'enter-once-sync') => $term instanceof \WP_Term ? html_entity_decode($term->name) : '–',
                __('Zentrum aktiv', 'enter-once-sync') => $source['enabled'] ? __('Ja', 'enter-once-sync') : __('Nein', 'enter-once-sync'),
                __('Probelauf', 'enter-once-sync') => $dry ? __('Ein – es wird nichts geschrieben', 'enter-once-sync') : __('Aus', 'enter-once-sync'),
                __('Letzter Abgleich', 'enter-once-sync') => self::time($st['last_run'] ?? null),
                __('Letzter erfolgreicher Abgleich', 'enter-once-sync') => self::time($st['last_success'] ?? null),
                __('Öffentlicher Feed', 'enter-once-sync') => $settings['public_feed_enabled'] ? self::feedUrl($id) : __('Aus', 'enter-once-sync'),
            ];
            if (isset($st['summary'])) {
                $sum = $st['summary'];
                $rows[__('Termine im Vorschau-Zeitraum', 'enter-once-sync')] = (string) $sum['occurrences_active'];
                $rows[__('Abgesagte Termine', 'enter-once-sync')] = (string) $sum['occurrences_cancelled'];
                $rows[__('Interne Einträge verworfen', 'enter-once-sync')] = (string) $sum['dropped_internal'];
            }
            echo '<table class="widefat striped" style="max-width:60rem"><tbody>';
            foreach ($rows as $k => $v) {
                echo '<tr><th style="width:18rem">' . esc_html($k) . '</th><td>' . esc_html($v) . '</td></tr>';
            }
            echo '</tbody></table><p>';
            echo self::button('sync_now', __('Jetzt abgleichen', 'enter-once-sync'), ['source' => $id], 'button button-primary'); // phpcs:ignore
            echo '</p>';

            if (!empty($st['held'])) {
                echo '<div class="notice notice-warning inline"><p><strong>' . esc_html(sprintf(__('Abgleich angehalten: %d Einträge würden auf einmal entfernt. Das ist mehr als die Obergrenze.', 'enter-once-sync'), count((array) $st['held_actions']))) . '</strong></p><ul>';
                foreach ((array) $st['held_actions'] as $a) {
                    echo '<li>' . esc_html($a['title'] . ($a['when'] !== '' ? ' (' . $a['when'] . ')' : '')) . '</li>';
                }
                echo '</ul><p>' . self::button('release_held', __('Trotzdem anwenden', 'enter-once-sync'), ['source' => $id, 'held_hash' => (string) ($st['held_hash'] ?? '')], 'button button-secondary') . '</p></div>'; // phpcs:ignore
            }
            foreach ((array) ($st['missing_terms'] ?? []) as $m) {
                echo '<div class="notice notice-warning inline"><p>' . esc_html(sprintf(__('Begriff nicht gefunden: %s', 'enter-once-sync'), $m)) . '</p></div>';
            }
            if (!empty($st['release_refused'])) {
                echo '<div class="notice notice-error inline"><p>' . esc_html((string) $st['release_refused']) . '</p></div>';
            }
            if (!empty($st['incomplete'])) {
                echo '<div class="notice notice-info inline"><p>' . esc_html(sprintf(__('%d Änderungen folgen beim nächsten Abgleich (Zeitbudget erreicht).', 'enter-once-sync'), (int) $st['incomplete'])) . '</p></div>';
            }
            foreach ((array) ($st['skipped'] ?? []) as $m) {
                echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Braucht eine Person:', 'enter-once-sync') . '</strong> ' . esc_html($m) . '</p></div>';
            }

            $actions = (array) ($st['actions'] ?? []);
            echo '<h3>' . esc_html(!empty($st['dry_run']) ? __('Geplante Aktionen (Probelauf)', 'enter-once-sync') : __('Änderungen des letzten Abgleichs', 'enter-once-sync')) . '</h3>';
            if ($actions === []) {
                echo '<p>' . esc_html__('Keine Änderungen.', 'enter-once-sync') . '</p>';
            } else {
                echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Aktion', 'enter-once-sync') . '</th><th>' . esc_html__('Art', 'enter-once-sync') . '</th><th>' . esc_html__('Titel', 'enter-once-sync') . '</th><th>' . esc_html__('Wann', 'enter-once-sync') . '</th><th>' . esc_html__('Grund', 'enter-once-sync') . '</th></tr></thead><tbody>';
                foreach ($actions as $a) {
                    $kind = $a['kind'] === 'weekly_offer' ? __('Wöchentliches Angebot', 'enter-once-sync') : __('Veranstaltung', 'enter-once-sync');
                    $why = self::reasonLabel((string) $a['reason']) . (!empty($a['note']) ? ' – ' . $a['note'] : '');
                    echo '<tr><td>' . esc_html(self::typeLabel((string) $a['type'])) . '</td><td>' . esc_html($kind) . '</td><td>' . esc_html((string) $a['title']) . '</td><td>' . esc_html((string) $a['when']) . '</td><td>' . esc_html($why) . '</td></tr>';
                }
                echo '</tbody></table>';
            }

            $notices = (array) ($st['notices'] ?? []);
            if ($notices !== []) {
                echo '<h3>' . esc_html__('Warnungen und Hinweise', 'enter-once-sync') . '</h3><ul>';
                usort($notices, static fn ($a, $b) => [$a['level'] !== 'warning', $a['title']] <=> [$b['level'] !== 'warning', $b['title']]);
                foreach ($notices as $n) {
                    echo '<li><strong>' . esc_html($n['level'] === 'warning' ? __('Warnung', 'enter-once-sync') : __('Hinweis', 'enter-once-sync')) . ':</strong> ' . esc_html((string) $n['message']) . '</li>';
                }
                echo '</ul>';
            }
        }
        $next = wp_next_scheduled(Scheduler::HOOK_SYNC);
        echo '<hr><p>' . esc_html(sprintf(__('Nächster automatischer Abgleich (WordPress-Zeitplaner): %s', 'enter-once-sync'), $next ? wp_date('d.m.Y H:i', $next) : '–')) . '</p>';
        echo '<p class="description">' . esc_html__('Kann der Server einen Zeitplaner-Eintrag ausführen, ist diese Zeile zuverlässiger:', 'enter-once-sync') . ' <code>' . esc_html(self::cronLine((int) $settings['sync_interval_minutes'], ABSPATH)) . '</code></p>';
    }

    private static function time(mixed $atom): string
    {
        if (!is_string($atom) || $atom === '') {
            return __('noch nie', 'enter-once-sync');
        }

        return wp_date('d.m.Y H:i', (int) strtotime($atom));
    }

    private function renderSettings(): void
    {
        $s = Options::get();
        $field = static function (string $label, string $html, string $help = ''): string {
            return '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $html . ($help !== '' ? '<p class="description">' . esc_html($help) . '</p>' : '') . '</td></tr>';
        };
        $text = static fn (string $name, $value, string $type = 'text', string $extra = ''): string => '<input type="' . esc_attr($type) . '" name="enteronce[' . esc_attr($name) . ']" value="' . esc_attr((string) $value) . '" class="regular-text" ' . $extra . '>';
        $number = static fn (string $name, $value, int $min): string => '<input type="number" min="' . $min . '" name="enteronce[' . esc_attr($name) . ']" value="' . esc_attr((string) $value) . '" class="small-text">';
        $check = static fn (string $name, bool $on, string $label): string => '<label><input type="checkbox" name="enteronce[' . esc_attr($name) . ']" value="1"' . checked($on, true, false) . '> ' . esc_html($label) . '</label>';
        $area = static fn (string $name, string $value, int $rows = 6): string => '<textarea name="enteronce[' . esc_attr($name) . ']" rows="' . $rows . '" class="large-text code">' . esc_textarea($value) . '</textarea>';
        $select = static function (string $name, string $value, array $options): string {
            $h = '<select name="enteronce[' . esc_attr($name) . ']">';
            foreach ($options as $k => $l) {
                $h .= '<option value="' . esc_attr((string) $k) . '"' . selected($value, (string) $k, false) . '>' . esc_html($l) . '</option>';
            }

            return $h . '</select>';
        };
        $centres = ['0' => __('– bitte wählen –', 'enter-once-sync')];
        $terms = taxonomy_exists(TermResolver::TAX_LOCATION) ? get_terms(['taxonomy' => TermResolver::TAX_LOCATION, 'hide_empty' => false]) : [];
        foreach (is_array($terms) ? $terms : [] as $t) {
            $centres[(string) $t->term_id] = html_entity_decode($t->name, ENT_QUOTES, 'UTF-8');
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="enteronce_save_settings">';
        wp_nonce_field('enteronce_save_settings');

        echo '<h2>' . esc_html__('Zentren', 'enter-once-sync') . '</h2>';
        $rows = $s['sources'];
        $rows[] = Options::sourceDefaults();
        foreach ($rows as $i => $src) {
            $new = $src['id'] === '';
            $p = 'sources][' . $i . '][';
            echo '<h3>' . esc_html($new ? __('Neues Zentrum hinzufügen', 'enter-once-sync') : Notifier::label($src)) . '</h3><table class="form-table"><tbody>';
            echo $field(__('Kennung', 'enter-once-sync'), $text($p . 'id', $src['id'], 'text', $new ? '' : 'readonly'), __('Kurz, ohne Leerzeichen, z. B. union. Wird im Feed verwendet und kann später nicht geändert werden.', 'enter-once-sync')); // phpcs:ignore
            echo $field(__('Name des Zentrums', 'enter-once-sync'), $text($p . 'label', $src['label'])); // phpcs:ignore
            $urlNote = $src['ics_url'] !== '' ? sprintf(__('Gespeichert: %s. Leer lassen, um die Adresse zu behalten.', 'enter-once-sync'), Secret::mask((string) $src['ics_url'])) : __('Geheim: wird nur hier gezeigt und nie protokolliert.', 'enter-once-sync');
            $urlField = '<input type="password" autocomplete="off" name="enteronce[' . esc_attr($p . 'ics_url') . ']" value="" class="regular-text" placeholder="https://…"> '
                . ($src['ics_url'] !== '' ? '<details style="display:inline-block"><summary>' . esc_html__('Anzeigen', 'enter-once-sync') . '</summary><code>' . esc_html((string) $src['ics_url']) . '</code></details> ' . $check($p . 'ics_url_clear', false, __('Adresse löschen', 'enter-once-sync')) : '');
            echo $field(__('Kalender-Adresse (ICS)', 'enter-once-sync'), $urlField, $urlNote); // phpcs:ignore
            echo $field(__('Zentrum auf der Verbands-Website', 'enter-once-sync'), $select($p . 'location_term', (string) $src['location_term'], $centres)); // phpcs:ignore
            echo $field(__('Benachrichtigung', 'enter-once-sync'), $area($p . 'notify_emails', implode("\n", (array) $src['notify_emails']), 2), __('Eine E-Mail-Adresse pro Zeile. Leer: die Adresse der Website-Administration.', 'enter-once-sync')); // phpcs:ignore
            echo $field(__('Zentrum aktiv', 'enter-once-sync'), $check($p . 'enabled', (bool) $src['enabled'], __('Automatisch abgleichen', 'enter-once-sync'))); // phpcs:ignore
            echo $field(__('Probelauf', 'enter-once-sync'), $check($p . 'dry_run', (bool) $src['dry_run'], __('Nur zeigen, was geändert würde; nichts schreiben', 'enter-once-sync')), __('Solange die Feldzuordnung nicht geprüft ist, gilt der Probelauf immer.', 'enter-once-sync')); // phpcs:ignore
            if (!$new) {
                echo $field(__('Entfernen', 'enter-once-sync'), $check($p . 'remove', false, __('Dieses Zentrum aus den Einstellungen entfernen (Einträge auf der Website bleiben)', 'enter-once-sync'))); // phpcs:ignore
            }
            echo '</tbody></table>';
        }

        echo '<h2>' . esc_html__('Abgleich', 'enter-once-sync') . '</h2><table class="form-table"><tbody>';
        echo $field(__('Abgleich-Abstand (Minuten)', 'enter-once-sync'), $number('sync_interval_minutes', $s['sync_interval_minutes'], 5)); // phpcs:ignore
        echo $field(__('Warnung «veraltet» nach (Minuten)', 'enter-once-sync'), $number('stale_after_minutes', $s['stale_after_minutes'], 10)); // phpcs:ignore
        echo $field(__('Vorschau-Zeitraum (Monate)', 'enter-once-sync'), $number('window_months_ahead', $s['window_months_ahead'], 1)); // phpcs:ignore
        echo $field(__('Sicherheitsabstand (Abgleiche)', 'enter-once-sync'), $number('removal_grace_runs', $s['removal_grace_runs'], 1), __('So oft hintereinander muss eine Veranstaltung im Kalender fehlen, bevor sie auf «Entwurf» gesetzt wird.', 'enter-once-sync')); // phpcs:ignore
        echo $field(__('Obergrenze (Entfernungen pro Abgleich)', 'enter-once-sync'), $number('bulk_unpublish_limit', $s['bulk_unpublish_limit'], 0), __('Würden mehr Einträge auf einmal entfernt, wird keiner entfernt und eine E-Mail verschickt.', 'enter-once-sync')); // phpcs:ignore
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Kennzeichen', 'enter-once-sync') . '</h2><table class="form-table"><tbody>';
        echo $field(__('Interne Einträge', 'enter-once-sync'), $check('internal_class_not_public', (bool) $s['internal_markers']['class_not_public'], __('Nicht öffentlich markierte Einträge verwerfen', 'enter-once-sync')) . '<br>' . $area('internal_categories', implode("\n", (array) $s['internal_markers']['categories']), 2), __('Kategorien, die einen Eintrag als intern kennzeichnen, eine pro Zeile.', 'enter-once-sync')); // phpcs:ignore
        echo $field(__('Abgesagt-Kennzeichen', 'enter-once-sync'), $area('cancel_prefixes', implode("\n", (array) $s['cancel_prefixes']), 3), __('Beginnt ein Titel mit einem dieser Wörter, gilt die Veranstaltung als abgesagt. Ein Wort pro Zeile.', 'enter-once-sync')); // phpcs:ignore
        echo $field(__('Abgesagte Veranstaltungen', 'enter-once-sync'), $select('cancel_mode', (string) $s['cancel_mode'], ['unpublish' => __('Ausblenden (Entwurf)', 'enter-once-sync'), 'badge' => __('Mit Vermerk ‹abgesagt› anzeigen', 'enter-once-sync')])); // phpcs:ignore
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Wöchentliche Angebote', 'enter-once-sync') . '</h2><table class="form-table"><tbody>';
        echo $field(__('Wöchentliche Termine', 'enter-once-sync'), $select('weekly_mode', (string) $s['weekly_mode'], ['weekly_offer' => __('Als Wöchentliches Angebot', 'enter-once-sync'), 'per_date' => __('Jeder Termin einzeln', 'enter-once-sync')])); // phpcs:ignore
        echo $field(__('Mindestanzahl Termine einer Serie', 'enter-once-sync'), $number('weekly_min_dates', $s['weekly_min_dates'], 1)); // phpcs:ignore
        echo $field(__('Vorlauf für Wöchentliche Angebote (Tage)', 'enter-once-sync'), $number('weekly_lead_days', $s['weekly_lead_days'], 0)); // phpcs:ignore
        echo $field(__('Hinweis bei verschobenem Termin', 'enter-once-sync'), $check('moved_date_note', (bool) $s['moved_date_note'], __('Oben im Text des Angebots anzeigen', 'enter-once-sync'))); // phpcs:ignore
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Zuordnung', 'enter-once-sync') . '</h2><table class="form-table"><tbody>';
        echo $field(__('Zuordnung Kategorien', 'enter-once-sync'), $area('category_map', self::formatMap((array) $s['category_map']), 11), __('Eine Zeile pro Kategorie: «Kalender = Thema; Thema». «Kalender = –» heisst: nicht übertragen.', 'enter-once-sync')); // phpcs:ignore
        echo $field(__('Zuordnung Zielgruppen', 'enter-once-sync'), $area('tag_map', self::formatMap((array) $s['tag_map']), 9), __('Eine Zeile pro Tag: «Kalender = Zielgruppe; Zielgruppe». «Kalender = –» heisst: nicht übertragen.', 'enter-once-sync')); // phpcs:ignore
        $syn = [];
        foreach ((array) $s['synonyms'] as $a => $b) {
            $syn[] = $a . ' = ' . $b;
        }
        echo $field(__('Schreibvarianten (Synonyme)', 'enter-once-sync'), $area('synonyms', implode("\n", $syn), 3), __('«Variante = Kalenderwert», z. B. «Familien = Familie».', 'enter-once-sync')); // phpcs:ignore
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Website des Verbands', 'enter-once-sync') . '</h2><table class="form-table"><tbody>';
        echo $field(__('Sprache (WPML)', 'enter-once-sync'), $select('wpml_mode', (string) $s['wpml_mode'], ['default_language' => __('Nur Standardsprache', 'enter-once-sync'), 'duplicate_all' => __('In alle Sprachen duplizieren', 'enter-once-sync')]), Integrations::wpmlActive() ? '' : __('WPML ist auf dieser Website nicht aktiv; die Einstellung hat keine Wirkung.', 'enter-once-sync')); // phpcs:ignore
        echo $field(__('Text der Website behalten', 'enter-once-sync'), $check('adopt_keep_text', (bool) $s['adopt_keep_text'], __('Voreinstellung beim Übernehmen bestehender Angebote', 'enter-once-sync'))); // phpcs:ignore
        $mapState = $s['field_map_verified'] ? __('geprüft', 'enter-once-sync') : __('nicht geprüft', 'enter-once-sync');
        echo $field(__('Feldzuordnung', 'enter-once-sync'), esc_html($mapState) . ' – <a href="' . esc_url(self::url('mapping')) . '">' . esc_html__('Zum Assistenten', 'enter-once-sync') . '</a>'); // phpcs:ignore
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Bilder', 'enter-once-sync') . '</h2><table class="form-table"><tbody>';
        echo $field(__('Bilder', 'enter-once-sync'), esc_html__('Aus der Medienbibliothek', 'enter-once-sync') . '<input type="hidden" name="enteronce[image_mode]" value="media_library">', __('Ein Bild gilt für ein Zentrum, wenn bei «Medien» im Feld «Zentrum (Enter Once)» das Zentrum gewählt ist. Der Dateiname entspricht dem Titel, z. B. Offener_Deutschtreff.jpg.', 'enter-once-sync')); // phpcs:ignore
        $ci = [];
        foreach ((array) $s['category_images'] as $c => $imgId) {
            $ci[] = $c . ' = ' . $imgId;
        }
        echo $field(__('Kategoriebilder', 'enter-once-sync'), $area('category_images', implode("\n", $ci), 4), __('«Kategorie = Bild-ID» pro Zeile. Die Bild-ID steht in der Medienbibliothek in der Adresse des Bildes.', 'enter-once-sync')); // phpcs:ignore
        echo $field(__('Standardbild (Bild-ID)', 'enter-once-sync'), $number('default_image_id', $s['default_image_id'], 0)); // phpcs:ignore
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Öffentlicher Feed', 'enter-once-sync') . '</h2><table class="form-table"><tbody>';
        echo $field(__('Öffentlicher Feed', 'enter-once-sync'), $check('public_feed_enabled', (bool) $s['public_feed_enabled'], __('Eingeschaltet', 'enter-once-sync'))); // phpcs:ignore
        echo $field(__('Erlaubte Websites für den Feed', 'enter-once-sync'), $area('feed_allowed_origins', implode("\n", (array) $s['feed_allowed_origins']), 2), __('«*» heisst: alle. Sonst eine Adresse pro Zeile, z. B. https://www.union-basel.ch', 'enter-once-sync')); // phpcs:ignore
        echo $field(__('Zwischenspeicher des Feeds (Sekunden)', 'enter-once-sync'), $number('feed_cache_seconds', $s['feed_cache_seconds'], 0)); // phpcs:ignore
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Benachrichtigung', 'enter-once-sync') . '</h2><table class="form-table"><tbody>';
        echo $field(__('Häufigkeit', 'enter-once-sync'), $select('digest_frequency', (string) $s['digest_frequency'], ['daily' => __('täglich', 'enter-once-sync'), 'each_run' => __('bei jedem Abgleich', 'enter-once-sync'), 'weekly' => __('wöchentlich', 'enter-once-sync')]), __('Gilt für die Zusammenfassung. Fehler und angehaltene Abgleiche werden immer sofort gemeldet, derselbe Fehler höchstens einmal pro Tag.', 'enter-once-sync')); // phpcs:ignore
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('KI-Funktionen', 'enter-once-sync') . '</h2><table class="form-table"><tbody>';
        echo $field(__('KI-Funktionen', 'enter-once-sync'), $check('ai_enabled', (bool) $s['ai_enabled'], __('Eingeschaltet', 'enter-once-sync')), __('In dieser Version nicht enthalten. Ohne KI arbeitet der Abgleich genau gleich.', 'enter-once-sync')); // phpcs:ignore
        echo '</tbody></table>';

        submit_button(__('Einstellungen speichern', 'enter-once-sync'));
        echo '</form>';
        echo self::button('resolve_terms', __('Begriffe neu zuordnen', 'enter-once-sync')); // phpcs:ignore
    }

    private function renderMapping(): void
    {
        $settings = Options::get();
        $state = FieldWizard::state();
        $map = Options::fieldMap($settings);
        $step = $state['step'];

        echo '<p>' . esc_html__('Der Assistent lernt, wie das Veranstaltungs-Tool seine Felder speichert. Sie ändern im Bearbeitungsbildschirm des Tools jeweils genau einen Wert und speichern; der Assistent vergleicht vorher und nachher. Das dauert rund eine halbe Stunde und ist Arbeit für die Entwicklerin oder den Entwickler des Tools oder eine technisch versierte Administratorin.', 'enter-once-sync') . '</p>';
        echo '<p>' . esc_html__('Der Assistent kann nur Felder lernen, die das Tool als gewöhnliche Beitragsfelder (Post-Meta) speichert. Speichert das Tool ein Feld anders, etwa in einer eigenen Tabelle, braucht es die Entwicklerin oder den Entwickler des Tools.', 'enter-once-sync') . '</p>';
        echo '<p><strong>' . esc_html__('Stand:', 'enter-once-sync') . '</strong> ' . esc_html($settings['field_map_verified'] ? __('Feldzuordnung geprüft.', 'enter-once-sync') : __('Feldzuordnung nicht geprüft – jeder Abgleich ist ein Probelauf.', 'enter-once-sync')) . '</p>';
        if ($state['message'] !== '') {
            echo '<div class="notice notice-' . ($state['ok'] ? 'success' : 'error') . ' inline"><p>' . esc_html($state['message']) . '</p></div>';
        }

        echo '<ol>';
        foreach (FieldMap::FIELDS as $f) {
            $entry = $settings['field_map'][$f] ?? null;
            $done = is_array($entry) ? (!empty($entry['none']) ? __('nicht vorhanden', 'enter-once-sync') : sprintf(__('gespeichert als %s', 'enter-once-sync'), $entry['meta'])) : '';
            echo '<li' . ($f === $step ? ' style="font-weight:600"' : '') . '>' . esc_html(FieldWizard::label($f)) . ($done !== '' ? ' – ' . esc_html($done) . ' ✓' : '') . '</li>';
        }
        echo '</ol>';

        echo '<div class="card" style="max-width:56rem"><p>' . esc_html(FieldWizard::instruction($step)) . '</p><p>';
        if ($step === 'start') {
            echo self::button('wizard', __('Entwurf gespeichert – weiter', 'enter-once-sync'), ['op' => 'begin'], 'button button-primary'); // phpcs:ignore
        } elseif ($step === 'noise' || in_array($step, FieldMap::FIELDS, true)) {
            if ($state['post_id'] > 0) {
                echo '<a class="button" target="_blank" href="' . esc_url((string) get_edit_post_link($state['post_id'], 'url')) . '">' . esc_html__('Lernentwurf öffnen', 'enter-once-sync') . '</a> ';
            }
            echo self::button('wizard', __('Gespeichert – vergleichen', 'enter-once-sync'), ['op' => 'compare'], 'button button-primary'); // phpcs:ignore
            if (in_array($step, FieldWizard::OPTIONAL, true)) {
                echo self::button('wizard', __('Dieses Feld gibt es nicht', 'enter-once-sync'), ['op' => 'skip']); // phpcs:ignore
            }
            if (!$state['ok']) {
                echo self::button('wizard', __('Ausgangszustand neu erfassen', 'enter-once-sync'), ['op' => 'resnapshot']); // phpcs:ignore
            }
        } elseif ($step === 'verify') {
            echo self::button('wizard', __('Prüfentwurf anlegen', 'enter-once-sync'), ['op' => 'testdraft'], 'button button-primary'); // phpcs:ignore
            $testStatus = $state['test_post_id'] > 0 ? get_post_status($state['test_post_id']) : false;
            if ($testStatus !== false && $testStatus !== 'trash') {
                echo '<a class="button" target="_blank" href="' . esc_url((string) get_edit_post_link($state['test_post_id'], 'url')) . '">' . esc_html__('Im Bearbeitungsbildschirm öffnen', 'enter-once-sync') . '</a> ';
                echo '<a class="button" target="_blank" href="' . esc_url(get_preview_post_link($state['test_post_id'])) . '">' . esc_html__('Vorschau öffnen', 'enter-once-sync') . '</a></p>';
                echo '<p>' . esc_html__('Im Prüfentwurf muss stehen:', 'enter-once-sync') . '</p><table class="widefat striped"><thead><tr><th></th><th>' . esc_html__('Erwartet', 'enter-once-sync') . '</th><th>' . esc_html__('Gelesen', 'enter-once-sync') . '</th><th>' . esc_html__('Selbstprüfung', 'enter-once-sync') . '</th></tr></thead><tbody>';
                $show = static function (string $field, mixed $value): string {
                    if ($value === null) {
                        return '–';
                    }
                    if (is_bool($value)) {
                        return $value ? __('ein', 'enter-once-sync') : __('aus', 'enter-once-sync');
                    }
                    if ($field === 'weekday') {
                        return GermanDate::weekdayName((int) $value);
                    }
                    if ($field === 'date' && is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
                        return $m[3] . '.' . $m[2] . '.' . $m[1];
                    }

                    return (string) $value;
                };
                foreach (FieldWizard::selfCheck($state['test_post_id']) as $f => $row) {
                    echo '<tr><th>' . esc_html(FieldWizard::label($f)) . '</th><td>' . esc_html($show($f, $row['expected'])) . '</td><td>' . esc_html($show($f, $row['actual'])) . '</td><td>'
                        . esc_html($row['same'] ? __('gelesen wie geschrieben', 'enter-once-sync') : __('abweichend', 'enter-once-sync')) . '</td></tr>';
                }
                echo '<tr><th>' . esc_html__('Titel', 'enter-once-sync') . '</th><td>' . esc_html(FieldWizard::TEST_TITLE) . '</td><td></td><td></td></tr></tbody></table><p>';
                // Review D1: confirmation only when every field reads back exactly as written.
                if (FieldWizard::selfCheckPasses($state['test_post_id'])) {
                    echo self::button('wizard', __('Alles stimmt – Zuordnung bestätigen', 'enter-once-sync'), ['op' => 'confirm'], 'button button-primary'); // phpcs:ignore
                } else {
                    echo '</p><div class="notice notice-error inline"><p>' . esc_html__('Die Selbstprüfung zeigt Abweichungen. Die Zuordnung kann so nicht bestätigt werden. Klicken Sie auf «Neu lernen» und gehen Sie die Schritte noch einmal durch.', 'enter-once-sync') . '</p></div><p>';
                }
            }
        }
        echo '</p></div><p>';
        echo self::button('wizard', __('Neu lernen', 'enter-once-sync'), ['op' => 'reset'], 'button button-link-delete'); // phpcs:ignore
        echo '</p>';
    }

    private function renderAdoption(): void
    {
        $settings = Options::get();
        $ids = Options::sourceIds($settings);
        if ($ids === []) {
            echo '<p>' . esc_html__('Noch kein Zentrum eingerichtet.', 'enter-once-sync') . '</p>';

            return;
        }
        $sourceId = sanitize_key((string) ($_GET['source'] ?? $ids[0]));
        if (!in_array($sourceId, $ids, true)) {
            $sourceId = $ids[0];
        }
        if (count($ids) > 1) {
            echo '<p>';
            foreach ($settings['sources'] as $src) {
                echo '<a class="button' . ($src['id'] === $sourceId ? ' button-primary' : '') . '" href="' . esc_url(self::url('adopt', ['source' => $src['id']])) . '">' . esc_html(Notifier::label($src)) . '</a> ';
            }
            echo '</p>';
        }
        $adoption = new Adoption($settings, Options::fieldMap($settings));
        echo '<p>' . esc_html__('Hier verbinden Sie von Hand erfasste Wöchentliche Angebote mit einer Serie aus dem Kalender. Das Plugin schlägt Serien mit gleichem Wochentag und überlappender Zeit vor, verbindet aber nie von selbst. Nicht verbundene Angebote bleiben für immer unverändert.', 'enter-once-sync') . '</p>';
        if (!$adoption->ready()) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Zuerst die Feldzuordnung lernen; ohne sie lassen sich Wöchentliche Angebote nicht erkennen.', 'enter-once-sync') . '</p></div>';

            return;
        }
        $series = $adoption->series($sourceId);
        if ($series === [] && empty(State::get($sourceId)['weekly_series'])) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('Noch keine Serien bekannt. Führen Sie zuerst einen Abgleich aus (ein Probelauf genügt).', 'enter-once-sync') . '</p></div>';
        }

        $compareId = absint($_GET['compare'] ?? 0);
        $compareKey = isset($_GET['series']) ? sanitize_text_field(rawurldecode((string) wp_unslash($_GET['series']))) : '';
        if ($compareId > 0 && $compareKey !== '') {
            foreach ($series as $s) {
                if ($s['key'] !== $compareKey) {
                    continue;
                }
                $keep = !empty($_GET['keep']);
                echo '<h2>' . esc_html(sprintf(__('Vergleich: «%1$s» und «%2$s»', 'enter-once-sync'), get_the_title($compareId), $s['title'])) . '</h2>';
                echo '<table class="widefat striped" style="max-width:70rem"><thead><tr><th></th><th>' . esc_html__('Website', 'enter-once-sync') . '</th><th>' . esc_html__('Kalender', 'enter-once-sync') . '</th><th>' . esc_html__('Nach der Übernahme', 'enter-once-sync') . '</th></tr></thead><tbody>';
                foreach ($adoption->compare($compareId, $s, $keep) as $row) {
                    $after = $row['same'] ? __('gleich', 'enter-once-sync') : ($row['kept'] ? __('bleibt wie auf der Website', 'enter-once-sync') : __('wird aus dem Kalender übernommen', 'enter-once-sync'));
                    echo '<tr><th>' . esc_html($row['label']) . '</th><td>' . esc_html($row['site']) . '</td><td>' . esc_html($row['calendar']) . '</td><td>' . esc_html($after) . '</td></tr>';
                }
                echo '</tbody></table>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="enteronce_adopt">';
                wp_nonce_field('enteronce_adopt');
                echo '<input type="hidden" name="source" value="' . esc_attr($sourceId) . '"><input type="hidden" name="post_id" value="' . (int) $compareId . '"><input type="hidden" name="series" value="' . esc_attr($compareKey) . '">';
                echo '<input type="hidden" name="keep_text" value="' . ($keep ? '1' : '') . '"><input type="hidden" name="op" value="confirm_adopt">';
                $status = (string) get_post_status($compareId);
                if ($status !== 'publish') {
                    // Review D6: a draft, pending or private offer is not published silently.
                    $label = get_post_status_object($status);
                    echo '<p>' . esc_html(sprintf(__('Dieses Angebot ist zurzeit nicht veröffentlicht («%s»). Es behält diesen Status, ausser Sie wählen:', 'enter-once-sync'), $label ? $label->label : $status)) . '</p>';
                    echo '<p><label><input type="checkbox" name="publish" value="1"> ' . esc_html__('Mit dem nächsten Abgleich veröffentlichen', 'enter-once-sync') . '</label></p>';
                }
                submit_button(__('Übernehmen', 'enter-once-sync'), 'primary', 'submit', false);
                echo ' <a class="button" href="' . esc_url(self::url('adopt', ['source' => $sourceId])) . '">' . esc_html__('Abbrechen', 'enter-once-sync') . '</a>';
                echo '</form><hr>';
            }
        }

        echo '<h2>' . esc_html__('Wöchentliche Angebote auf der Website', 'enter-once-sync') . '</h2>';
        $offers = $adoption->siteOffers($sourceId);
        if ($offers === []) {
            echo '<p>' . esc_html__('Keine nicht übernommenen Wöchentlichen Angebote gefunden.', 'enter-once-sync') . '</p>';
        }
        foreach ($offers as $o) {
            $cands = Adoption::candidates($o, $series);
            $when = ($o['weekday'] !== null ? GermanDate::weekdayName((int) $o['weekday']) : '?') . ' ' . ($o['time_start'] ?? '?') . '–' . ($o['time_end'] ?? '?');
            echo '<div class="card" style="max-width:60rem"><h3>' . esc_html($o['title']) . ' <span style="font-weight:normal">(' . esc_html($when) . ')</span></h3>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="enteronce_adopt">';
            wp_nonce_field('enteronce_adopt');
            echo '<input type="hidden" name="source" value="' . esc_attr($sourceId) . '"><input type="hidden" name="post_id" value="' . (int) $o['id'] . '"><input type="hidden" name="op" value="compare">';
            echo '<fieldset><label><input type="radio" name="series" value="none" checked> ' . esc_html__('Nicht übernehmen', 'enter-once-sync') . '</label><br>';
            foreach ($cands as $c) {
                $range = $c['first_date'] . ($c['last_date'] !== null ? ' – ' . $c['last_date'] : '');
                echo '<label><input type="radio" name="series" value="' . esc_attr($c['key']) . '"> ' . esc_html(sprintf('%s (%s %s–%s, %s)', $c['title'], GermanDate::weekdayName((int) $c['weekday']), $c['time_start'], $c['time_end'], $range)) . '</label><br>';
            }
            if ($cands === []) {
                echo '<em>' . esc_html__('Keine Serie mit gleichem Wochentag und überlappender Zeit.', 'enter-once-sync') . '</em><br>';
            }
            echo '</fieldset><p><label><input type="checkbox" name="keep_text" value="1"' . checked((bool) $settings['adopt_keep_text'], true, false) . '> ' . esc_html__('Text der Website behalten', 'enter-once-sync') . '</label></p>';
            echo '<button class="button button-primary">' . esc_html__('Vergleich anzeigen', 'enter-once-sync') . '</button></form></div>';
        }

        $adopted = $adoption->adopted($sourceId);
        if ($adopted !== []) {
            echo '<h2>' . esc_html__('Übernommene Angebote', 'enter-once-sync') . '</h2><table class="widefat striped" style="max-width:60rem"><tbody>';
            foreach ($adopted as $a) {
                echo '<tr><td>' . esc_html($a['title']) . '</td><td>' . esc_html(sprintf(__('übernommen am %s', 'enter-once-sync'), $a['adopted'] !== '' ? wp_date('d.m.Y H:i', (int) strtotime($a['adopted'])) : '–')) . '</td><td>'
                    . self::button('undo_adoption', __('Übernahme rückgängig machen', 'enter-once-sync'), ['source' => $sourceId, 'post_id' => $a['id']]) . '</td></tr>'; // phpcs:ignore
            }
            echo '</tbody></table>';
        }
    }
}

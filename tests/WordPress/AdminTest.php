<?php

declare(strict_types=1);

namespace EnterOnce\Tests\WordPress;

use EnterOnce\WordPress\Admin;
use EnterOnce\WordPress\EditNotice;
use EnterOnce\WordPress\Options;
use EnterOnce\WordPress\State;

/** Plan 4.7 (settings page, status page) and 4.9 (permissions, nonces, sanitising, secret address). */
final class AdminTest extends WpTestCase
{
    private const SECRET = 'https://calendar.example.org/ical/secret-abc-987/basic.ics';

    protected function setUp(): void
    {
        parent::setUp();
        // What WordPress loads for every admin screen.
        require_once ABSPATH . 'wp-admin/includes/template.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        add_filter('wp_die_handler', [$this, 'dieHandler']);
        add_filter('wp_redirect', [$this, 'redirect']);
    }

    protected function tearDown(): void
    {
        remove_filter('wp_die_handler', [$this, 'dieHandler']);
        remove_filter('wp_redirect', [$this, 'redirect']);
        $_GET = $_POST = $_REQUEST = [];
        parent::tearDown();
    }

    public function dieHandler(): callable
    {
        return static function ($message): void {
            throw new \RuntimeException('wp_die: ' . (is_string($message) ? $message : 'error'));
        };
    }

    public function redirect(string $location): string
    {
        throw new \LogicException('redirect: ' . $location);
    }

    private function asAdmin(): void
    {
        wp_set_current_user(get_user_by('login', 'admin')->ID);
    }

    private function asEditor(): void
    {
        $id = username_exists('enteronce-editor') ?: wp_create_user('enteronce-editor', wp_generate_password(), 'editor@example.org');
        (new \WP_User((int) $id))->set_role('editor');
        wp_set_current_user((int) $id);
    }

    private function render(string $tab): string
    {
        $_GET = ['page' => Admin::SLUG, 'tab' => $tab];
        ob_start();
        try {
            (new Admin())->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    private function post(string $action, array $fields): string
    {
        $_POST = $_REQUEST = array_merge($fields, ['action' => 'enteronce_' . $action, '_wpnonce' => wp_create_nonce('enteronce_' . $action)]);
        try {
            $method = 'handle' . str_replace('_', '', ucwords($action, '_'));
            (new Admin())->$method();
        } catch (\LogicException $e) {
            return $e->getMessage();
        }
        self::fail('no redirect');
    }

    public function testEveryTabRendersForAdministrators(): void
    {
        $settings = Options::get();
        $settings['sources'][0]['ics_url'] = self::SECRET;
        Options::save($settings);
        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        $this->asAdmin();
        $status = $this->render('status');
        foreach (['Jetzt abgleichen', 'Kalender-Adresse (ICS)', 'Probelauf', 'Letzter erfolgreicher Abgleich', 'Änderungen des letzten Abgleichs', 'Repair Café', 'Warnung', 'Hinweis'] as $label) {
            self::assertStringContainsString($label, $status);
        }
        self::assertStringNotContainsString('secret-abc-987', $status, 'the status page shows the address masked');
        self::assertStringContainsString('https://calendar.example.org/…', $status);

        $settingsPage = $this->render('settings');
        foreach (['Kalender-Adresse (ICS)', 'Benachrichtigung', 'Vorschau-Zeitraum', 'Sicherheitsabstand', 'Obergrenze', 'Abgesagt-Kennzeichen', 'Abgesagte Veranstaltungen', 'Wöchentliche Termine', 'Zuordnung Kategorien', 'Zuordnung Zielgruppen', 'Text der Website behalten', 'Öffentlicher Feed', 'Häufigkeit', 'KI-Funktionen', 'Kultur = Kulturelles &amp; Musik', 'Highlight = –'] as $label) {
            self::assertStringContainsString($label, $settingsPage, $label);
        }
        self::assertStringContainsString('secret-abc-987', $settingsPage, 'administrators can see the full address on the settings page');
        self::assertStringContainsString('type="password"', $settingsPage);
        self::assertStringNotContainsString('ß', $status . $settingsPage, 'Swiss spelling');

        self::assertStringContainsString('Entwurf gespeichert – weiter', $this->render('mapping'));
        $adopt = $this->render('adopt');
        self::assertStringContainsString('Bestehende Angebote übernehmen', $adopt);
        self::assertStringContainsString('Deutsch im Alltag A', $adopt);
    }

    public function testOnlyAdministratorsGetIn(): void
    {
        $this->asEditor();
        try {
            $this->render('status');
            self::fail('editor got in');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('wp_die', $e->getMessage());
        }
        $_POST = $_REQUEST = ['source' => 'union', '_wpnonce' => wp_create_nonce('enteronce_sync_now')];
        try {
            (new Admin())->handleSyncNow();
            self::fail('editor ran a sync');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('wp_die', $e->getMessage());
        }
        self::assertSame([], State::get('union'));
    }

    public function testActionsNeedANonce(): void
    {
        $this->asAdmin();
        $_POST = $_REQUEST = ['source' => 'union', '_wpnonce' => 'falsch'];
        try {
            (new Admin())->handleSyncNow();
            self::fail('no nonce check');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('wp_die', $e->getMessage());
        }
    }

    public function testSaveSettingsSanitisesAndKeepsTheSecret(): void
    {
        $settings = Options::get();
        $settings['sources'][0]['ics_url'] = self::SECRET;
        Options::save($settings);
        $this->asAdmin();
        $form = [
            'sources' => [
                ['id' => 'union', 'label' => 'Quartierzentrum Union <b>', 'ics_url' => '', 'location_term' => (string) self::centre('Quartierzentrum Union'), 'notify_emails' => "team@example.org\nkeine-adresse", 'enabled' => '1', 'dry_run' => '1'],
                ['id' => 'Neu Zentrum!', 'label' => 'Neu', 'ics_url' => 'http://unsicher.example.org/cal.ics', 'location_term' => '0', 'notify_emails' => ''],
            ],
            'sync_interval_minutes' => '1', 'stale_after_minutes' => '60', 'window_months_ahead' => '3', 'removal_grace_runs' => '2', 'bulk_unpublish_limit' => '5',
            'internal_class_not_public' => '1', 'internal_categories' => "Intern\nPrivat", 'cancel_prefixes' => "Abgesagt:\nFällt aus:", 'cancel_mode' => 'badge',
            'weekly_mode' => 'per_date', 'weekly_min_dates' => '4', 'weekly_lead_days' => '14', 'moved_date_note' => '1',
            'category_map' => "Kultur = Kulturelles & Musik\nParty = –\nBewegung = Bewegung & Entspannung; Offener Treffpunkt", 'tag_map' => "Kinder = Für Kinder", 'synonyms' => 'Familien = Familie',
            'wpml_mode' => 'default_language', 'adopt_keep_text' => '1', 'category_images' => "Kultur = 12\nkaputt", 'default_image_id' => '0',
            'public_feed_enabled' => '1', 'feed_allowed_origins' => "https://www.example.org/\n*", 'feed_cache_seconds' => '300', 'digest_frequency' => 'weekly',
        ];
        $location = $this->post('save_settings', ['enteronce' => wp_slash($form)]);
        self::assertStringContainsString('tab=settings', $location);
        $s = Options::get();
        self::assertSame(self::SECRET, $s['sources'][0]['ics_url'], 'an empty field keeps the stored address');
        self::assertSame('Quartierzentrum Union', $s['sources'][0]['label']);
        self::assertSame(['team@example.org'], $s['sources'][0]['notify_emails']);
        self::assertSame('neuzentrum', $s['sources'][1]['id']);
        self::assertSame('', $s['sources'][1]['ics_url'], 'http is refused (R-02)');
        self::assertTrue($s['sources'][1]['dry_run'] === false || $s['sources'][1]['dry_run'] === true);
        self::assertSame(5, $s['sync_interval_minutes'], 'bounded');
        self::assertSame(['Abgesagt:', 'Fällt aus:'], $s['cancel_prefixes']);
        self::assertSame('badge', $s['cancel_mode']);
        self::assertSame('per_date', $s['weekly_mode']);
        self::assertSame(['Kultur' => ['Kulturelles & Musik'], 'Party' => null, 'Bewegung' => ['Bewegung & Entspannung', 'Offener Treffpunkt']], $s['category_map']);
        self::assertSame(['Kultur' => 12], $s['category_images']);
        self::assertSame(['https://www.example.org', '*'], $s['feed_allowed_origins']);
        self::assertSame('weekly', $s['digest_frequency']);
        self::assertSame(self::standinFieldMap(), $s['field_map'], 'the form never changes the field map');
        self::assertTrue($s['field_map_verified']);
        self::assertSame(array_keys(Options::defaults()), array_keys(get_option(Options::OPTION)));
    }

    public function testSyncNowAndReleaseHeldButtons(): void
    {
        $this->fetcherFilter = fn () => $this->fetcher;
        $this->fetcher->set(self::UNION_URL, self::fixture('union-beispielkalender.ics'));
        add_filter('enteronce_fetcher', $this->fetcherFilter);
        add_filter('enteronce_clock', fn () => $this->clock);
        try {
            $this->asAdmin();
            $this->post('sync_now', ['source' => 'union']);
            self::assertCount(8, self::managed());
            self::assertSame('manual', State::get('union')['trigger']);
            $this->post('release_held', ['source' => 'union']);
            self::assertSame('manual', State::get('union')['trigger']);
        } finally {
            remove_all_filters('enteronce_fetcher');
            remove_all_filters('enteronce_clock');
        }
    }

    /** @var callable|null */
    private $fetcherFilter = null;

    public function testEditNoticeOnManagedRecords(): void
    {
        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        $this->asAdmin();
        $id = self::byKey('union:union-mittwochsbar-1125@example');
        require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        $GLOBALS['current_screen'] = \WP_Screen::get('events');
        $GLOBALS['current_screen']->base = 'post';
        $GLOBALS['current_screen']->post_type = 'events';
        $_GET = ['post' => (string) $id];
        ob_start();
        EditNotice::render();
        $html = (string) ob_get_clean();
        self::assertStringContainsString('Dieser Eintrag kommt aus dem Kalender', $html);
        $_GET = ['post' => (string) $this->seeded['kinderpiazza-17']];
        ob_start();
        EditNotice::render();
        self::assertSame('', (string) ob_get_clean(), 'no notice on hand-entered records');
    }
}

<?php

declare(strict_types=1);

namespace EnterOnce\Tests\WordPress;

use EnterOnce\Core\Action;
use EnterOnce\WordPress\Admin;
use EnterOnce\WordPress\Adoption;
use EnterOnce\WordPress\EventAdapter;
use EnterOnce\WordPress\FeedController;
use EnterOnce\WordPress\FetchError;
use EnterOnce\WordPress\FieldWizard;
use EnterOnce\WordPress\HttpFetcher;
use EnterOnce\WordPress\Images;
use EnterOnce\WordPress\Notifier;
use EnterOnce\WordPress\Options;
use EnterOnce\WordPress\Runner;
use EnterOnce\WordPress\RunReport;
use EnterOnce\WordPress\State;

/** One test (or more) per finding of the independent review (D1–D9 and the should-fix list). */
final class ReviewFixesTest extends WpTestCase
{
    private const KEY_TH = 'union:040000008200E00074C5B7101A82E00800000000204575056568DD0100000000000000001000000041DA350A57AE144E9A0F7570EF064911:TH';
    private const KEY_MO = 'union:040000008200E00074C5B7101A82E0080000000050756CA84277DD0100000000000000001000000088CC4D047EC66C4CA4C9D244DCA87503:MO';

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request', 0);
        parent::tearDown();
    }

    private function runner(?int $budget = null): Runner
    {
        return new Runner($this->clock, $this->fetcher, new Notifier($this->clock), $budget);
    }

    private function feedRequest(array $params): \WP_REST_Response|\WP_Error
    {
        $clock = $this->clock;
        $filter = static fn () => $clock;
        add_filter('enteronce_clock', $filter);
        try {
            $r = new \WP_REST_Request('GET', '/enteronce/v1/events');
            $r->set_query_params($params);

            return rest_do_request($r);
        } finally {
            remove_filter('enteronce_clock', $filter);
        }
    }

    /* ---------------------------------------------------------------- D1 */

    /** The wizard plays through to «Ende», where the administrator changes «Beginn» by mistake. */
    public function testD1WizardRefusesAKeyAlreadyLearnedForAnotherField(): void
    {
        FieldWizard::reset();
        $draft = (int) wp_insert_post(['post_type' => 'events', 'post_status' => 'draft', 'post_title' => FieldWizard::LEARN_TITLE]);
        qtp_standin_apply_form($draft, []);
        $edit = static function (array $changes) use ($draft): void {
            qtp_standin_apply_form($draft, array_replace(qtp_standin_form_values($draft), $changes));
        };
        $w = new FieldWizard();
        self::assertTrue($w->begin());
        $edit([]);
        self::assertTrue($w->compare());
        $edit(['qx_a' => '2031-04-23']);
        self::assertTrue($w->compare());
        $edit(['qx_b' => '07:45']);
        self::assertTrue($w->compare());
        // «Ende» step: «Beginn» changed instead of «Ende».
        $edit(['qx_b' => '09:05']);
        self::assertFalse($w->compare());
        self::assertStringContainsString('bereits als «Beginn» gelernt', FieldWizard::state()['message']);
        self::assertArrayNotHasKey('time_end', Options::get()['field_map']);
        self::assertSame('time_end', FieldWizard::state()['step']);
    }

    public function testD1ConfirmNeedsAPassingSelfCheckAndAnExistingTestDraft(): void
    {
        // The reproduced case: a complete map in which the end time was learned on the start field.
        $map = self::standinFieldMap();
        $map['time_end'] = $map['time_start'];
        $this->configure(['field_map' => $map, 'field_map_verified' => false]);
        update_option(FieldWizard::OPTION, ['step' => 'verify', 'post_id' => 0, 'snapshot' => [], 'test_post_id' => 0, 'message' => '', 'ok' => true, 'ignore' => []], false);
        $w = new FieldWizard();
        $test = $w->writeTestDraft();
        self::assertFalse(FieldWizard::selfCheckPasses($test));
        self::assertFalse($w->confirm(), 'a failing self-check cannot be confirmed');
        self::assertFalse(Options::get()['field_map_verified']);
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        $_GET = ['page' => Admin::SLUG, 'tab' => 'mapping'];
        require_once ABSPATH . 'wp-admin/includes/template.php';
        ob_start();
        (new Admin())->render();
        $html = (string) ob_get_clean();
        self::assertStringNotContainsString('Alles stimmt – Zuordnung bestätigen', $html, 'the button is hidden');
        self::assertStringContainsString('abweichend', $html);
        self::assertStringContainsString('Erwartet', $html);

        // A correct map, but the test draft was deleted meanwhile.
        $this->configure(['field_map_verified' => false]);
        $test = $w->writeTestDraft();
        self::assertTrue(FieldWizard::selfCheckPasses($test));
        wp_trash_post($test);
        self::assertFalse($w->confirm());
        $w->writeTestDraft();
        self::assertTrue($w->confirm());
        self::assertTrue(Options::get()['field_map_verified']);
    }

    public function testD1ANewlyVerifiedMapRewritesEveryRecord(): void
    {
        $ics = self::fixture('union-beispielkalender.ics');
        $this->sync($ics, '2026-11-19 00:00');
        self::assertSame(0, $this->sync($ics, '2026-11-19 00:10')->counts['update']);
        // The map is relearned: the sidebar text now lives in another field.
        $map = self::standinFieldMap();
        $map['info_text'] = ['meta' => '_qtp_orginfo', 'format' => 'text'];
        $this->configure(['field_map' => $map]);
        $report = $this->sync($ics, '2026-11-19 00:20');
        self::assertSame(8, $report->counts['update'], 'every record is rewritten with the new map');
        self::assertSame(0, $this->sync($ics, '2026-11-19 00:30')->counts['update'], 'once');
    }

    /* ---------------------------------------------------------------- D2 */

    public function testD2AnInterruptedCreateLeavesAManagedDraftThatTheNextRunFinishes(): void
    {
        $thrown = false;
        $crash = static function (int $id) use (&$thrown): void {
            if (!$thrown && get_post_meta($id, EventAdapter::META_KEY, true) === 'union:union-mittwochsbar-1125@example') {
                $thrown = true;
                throw new \RuntimeException('another plugin failed in save_post');
            }
        };
        add_action('save_post_events', $crash, 5);
        try {
            $this->clock->set('2026-11-19 00:00');
            $this->fetcher->set(self::UNION_URL, self::fixture('union-beispielkalender.ics'));
            $report = $this->runner()->run('union');
        } finally {
            remove_action('save_post_events', $crash, 5);
        }
        self::assertSame(RunReport::ERROR, $report->status);
        $ids = get_posts(['post_type' => 'events', 'post_status' => 'any', 'title' => 'MittwochsBAR im Union', 'fields' => 'ids']);
        self::assertCount(1, $ids);
        self::assertSame('draft', get_post_status($ids[0]), 'not published before it is complete');
        self::assertSame('1', get_post_meta($ids[0], EventAdapter::META_MANAGED, true), 'but already managed');

        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:15');
        $ids = get_posts(['post_type' => 'events', 'post_status' => 'any', 'title' => 'MittwochsBAR im Union', 'fields' => 'ids']);
        self::assertCount(1, $ids, 'no second record');
        self::assertSame('publish', get_post_status($ids[0]));
        self::assertCount(8, self::managed('union', 'publish'));
    }

    public function testD2TimeBudgetStopsBetweenActionsAndTheNextRunResumes(): void
    {
        $this->clock->set('2026-11-19 00:00');
        $this->fetcher->set(self::UNION_URL, self::fixture('union-beispielkalender.ics'));
        $first = $this->runner(0)->run('union');
        self::assertSame(1, count(self::managed()), 'one change, then the budget is used up');
        self::assertSame(7, $first->remaining);
        self::assertSame(7, State::get('union')['incomplete']);
        $this->clock->set('2026-11-19 00:15');
        $second = $this->runner()->run('union');
        self::assertSame(0, $second->remaining);
        self::assertCount(8, self::managed('union', 'publish'));
        self::assertCount(8, get_posts(['post_type' => 'events', 'post_status' => 'any', 'meta_key' => EventAdapter::META_MANAGED, 'fields' => 'ids', 'posts_per_page' => -1]));
    }

    public function testD2TheLockRefusesASecondRunAndLastsLongerThanTheBudget(): void
    {
        add_option('enteronce_lock_union', (string) (time() + 300), '', false);
        $report = $this->runner()->run('union');
        self::assertSame(RunReport::LOCKED, $report->status);
        delete_option('enteronce_lock_union');

        // A run's own lock outlives its time budget.
        $seen = null;
        $spy = static function () use (&$seen): void {
            $seen = (int) get_option('enteronce_lock_union');
        };
        add_action('save_post_events', $spy);
        try {
            $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        } finally {
            remove_action('save_post_events', $spy);
        }
        self::assertGreaterThanOrEqual(time() + Runner::TIME_BUDGET + Runner::LOCK_MARGIN - 5, $seen);
        self::assertFalse(get_option('enteronce_lock_union'), 'released after the run');
    }

    /* ---------------------------------------------------------------- D3 */

    public function testD3HtmlTitlesAreStoredAsPlainText(): void
    {
        wp_set_current_user(get_user_by('login', 'admin')->ID); // unfiltered_html: WordPress would not filter
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:x\r\nBEGIN:VEVENT\r\nUID:xss@example.org\r\n"
            . "SUMMARY:Konzert <img src=x onerror=alert(1)>\r\nLOCATION:<b>Saal</b>\r\n"
            . "DTSTART;TZID=Europe/Zurich:20261125T190000\r\nDTEND;TZID=Europe/Zurich:20261125T210000\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->sync($ics, '2026-11-19 00:00');
        $id = self::byKey('union:xss@example.org');
        self::assertSame('Konzert', get_post_field('post_title', $id, 'raw'));
        self::assertStringNotContainsString('<', (string) get_post_meta($id, '_qtp_orginfo', true) === '' ? '' : wp_strip_all_tags((string) get_post_meta($id, '_qtp_orginfo', true)));
        $feed = $this->feedRequest(['source' => 'union'])->get_data();
        self::assertSame('Konzert', $feed[0]['title']);
        self::assertSame('Saal', $feed[0]['room']);
    }

    /* ---------------------------------------------------------------- D4 */

    public function testD4OneCacheEntryWhateverTheRangesAndStrictDates(): void
    {
        global $wpdb;
        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        for ($i = 0; $i < 200; ++$i) {
            $from = (new \DateTimeImmutable('2026-11-19'))->modify("+$i days")->format('Y-m-d');
            self::assertSame(200, $this->feedRequest(['source' => 'union', 'from' => $from])->get_status());
        }
        $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_enteronce\\_feed%'");
        self::assertSame(1, $rows, 'one cache entry per source and data version');
        self::assertSame(400, $this->feedRequest(['source' => 'union', 'from' => '2027-13-45'])->get_status());
        self::assertSame(400, $this->feedRequest(['source' => 'union', 'to' => '2026-02-30'])->get_status());
        $all = $this->feedRequest(['source' => 'union', 'from' => '1999-01-01', 'to' => '2099-12-31'])->get_data();
        self::assertSame($this->feedRequest(['source' => 'union'])->get_data(), $all, 'from and to are clamped to the window');
    }

    /* ---------------------------------------------------------------- D5, D6 */

    /** A hand-entered Thursday offer that belongs to two centres and has a hand-set topic. */
    private function twoCentreOffer(): int
    {
        $id = qtp_standin_record('Deutschtreff (von Hand)', 'deutschtreff-hand', 'Quartierzentrum Union', ['qx_a' => '2026-08-20', 'qx_b' => '15:00', 'qx_c' => '17:00', 'qx_d' => '1', 'qx_e' => 'Do'], ['Beratung & Bildung'], ['Für Erwachsene']);
        wp_set_object_terms($id, [self::centre('Quartierzentrum Union'), self::centre('Quartiertreffpunkt Rheinbogen')], 'location_cat');

        return $id;
    }

    public function testD5AdoptionKeepsOtherCentresAndHandSetTerms(): void
    {
        $this->configure([], true);
        $id = $this->twoCentreOffer();
        $ics = self::fixture('calendar.ics'); // «Test: Offener Deutschtreff» (Thursday 15:00) has no category block
        $this->sync($ics, '2027-01-04 00:00');
        $adoption = new Adoption(Options::get(), Options::fieldMap(Options::get()));
        $rows = array_column($adoption->compare($id, array_values(array_filter($adoption->series('union'), static fn ($s) => $s['key'] === self::KEY_TH))[0], true), null, 'label');
        self::assertTrue($rows['Themen']['kept'], 'the comparison says the topics stay');
        self::assertTrue($rows['Zentren']['kept']);
        self::assertTrue($adoption->adopt($id, 'union', self::KEY_TH, true, $this->clock->now()));
        $this->configure();
        $this->sync($ics, '2027-01-04 00:10');
        self::assertEqualsCanonicalizing(['Quartierzentrum Union', 'Quartiertreffpunkt Rheinbogen'], wp_get_object_terms($id, 'location_cat', ['fields' => 'names']), 'the other centre stays');
        self::assertSame(['Beratung & Bildung'], array_map('html_entity_decode', wp_get_object_terms($id, 'topic', ['fields' => 'names'])), 'hand-set topic stays');
        self::assertSame(['Für Erwachsene'], wp_get_object_terms($id, 'targetgroup', ['fields' => 'names']));
    }

    public function testD5UpdatesNeverRemoveAnotherCentre(): void
    {
        $ics = self::fixture('union-beispielkalender.ics');
        $this->sync($ics, '2026-11-19 00:00');
        $bar = self::byKey('union:union-mittwochsbar-1125@example');
        wp_set_object_terms($bar, [self::centre('Quartiertreffpunkt Rheinbogen')], 'location_cat', true);
        $this->sync(str_replace('SUMMARY:MittwochsBAR im Union', 'SUMMARY:MittwochsBAR', $ics), '2026-11-19 00:10');
        self::assertSame('MittwochsBAR', get_the_title($bar));
        self::assertEqualsCanonicalizing(['Quartierzentrum Union', 'Quartiertreffpunkt Rheinbogen'], wp_get_object_terms($bar, 'location_cat', ['fields' => 'names']));
    }

    public function testD5UndoRestoresTheOfferAndReleasesIt(): void
    {
        $this->configure([], true);
        $id = $this->twoCentreOffer();
        $before = [get_post_field('post_title', $id), get_post_field('post_content', $id), self::allMeta($id), wp_get_object_terms($id, ['topic', 'targetgroup', 'location_cat'], ['fields' => 'ids'])];
        $ics = self::fixture('calendar.ics');
        $this->sync($ics, '2027-01-04 00:00');
        $adoption = new Adoption(Options::get(), Options::fieldMap(Options::get()));
        self::assertTrue($adoption->adopt($id, 'union', self::KEY_TH, false, $this->clock->now()));
        $this->configure();
        $this->sync($ics, '2027-01-04 00:10');
        self::assertSame('Test: Offener Deutschtreff', get_the_title($id));
        self::assertCount(1, $adoption->adopted('union'));
        self::assertTrue(Adoption::undo($id));
        $after = [get_post_field('post_title', $id), get_post_field('post_content', $id), self::allMeta($id), wp_get_object_terms($id, ['topic', 'targetgroup', 'location_cat'], ['fields' => 'ids'])];
        self::assertSame($before[0], $after[0]);
        self::assertSame($before[1], $after[1]);
        self::assertSame($before[2], $after[2], 'every field as before, no _enteronce_ meta');
        self::assertEqualsCanonicalizing($before[3], $after[3]);
        self::assertSame([], $adoption->adopted('union'));
    }

    public function testD6ADraftOfferStaysADraftUnlessPublishingIsChosen(): void
    {
        $this->configure([], true);
        $id = $this->twoCentreOffer();
        wp_update_post(['ID' => $id, 'post_status' => 'draft']);
        $ics = self::fixture('calendar.ics');
        $this->sync($ics, '2027-01-04 00:00');
        $adoption = new Adoption(Options::get(), Options::fieldMap(Options::get()));
        self::assertTrue($adoption->adopt($id, 'union', self::KEY_TH, true, $this->clock->now()));
        $this->configure();
        $report = $this->sync($ics, '2027-01-04 00:10');
        self::assertSame('draft', get_post_status($id), 'not published silently');
        self::assertSame('1500', get_post_meta($id, '_qtp_t1', true));
        self::assertSame(0, $this->sync($ics, '2027-01-04 00:20')->counts['update'], 'and no churn afterwards');
        unset($report);

        Adoption::undo($id);
        wp_update_post(['ID' => $id, 'post_status' => 'draft']);
        $this->configure([], true);
        $this->sync($ics, '2027-01-04 00:30');
        self::assertTrue((new Adoption(Options::get(), Options::fieldMap(Options::get())))->adopt($id, 'union', self::KEY_TH, true, $this->clock->now(), true));
        $this->configure();
        $this->sync($ics, '2027-01-04 00:40');
        self::assertSame('publish', get_post_status($id), 'published because it was chosen');
    }

    public function testD6AdoptionOnlyFromTheComparisonView(): void
    {
        $this->configure([], true);
        $this->sync(self::fixture('calendar.ics'), '2027-01-04 00:00');
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        $redirect = null;
        $filter = static function (string $location) use (&$redirect): string {
            $redirect = $location;
            throw new \LogicException('redirect');
        };
        add_filter('wp_redirect', $filter);
        $id = $this->seeded['deutsch-fuer-den-alltag-2'];
        $_POST = $_REQUEST = ['source' => 'union', 'post_id' => (string) $id, 'series' => self::KEY_MO, 'keep_text' => '1', 'op' => 'adopt', '_wpnonce' => wp_create_nonce('enteronce_adopt')];
        try {
            (new Admin())->handleAdopt();
        } catch (\LogicException) {
        } finally {
            remove_filter('wp_redirect', $filter);
        }
        self::assertStringContainsString('compare=' . $id, (string) $redirect, 'an «adopt» from a card only opens the comparison');
        self::assertSame('', get_post_meta($id, EventAdapter::META_MANAGED, true));
        require_once ABSPATH . 'wp-admin/includes/template.php';
        $_GET = ['page' => Admin::SLUG, 'tab' => 'adopt', 'source' => 'union'];
        ob_start();
        (new Admin())->render();
        $cards = (string) ob_get_clean();
        self::assertStringNotContainsString('confirm_adopt', $cards, 'no adopt button outside the comparison');
        $_GET = ['page' => Admin::SLUG, 'tab' => 'adopt', 'source' => 'union', 'compare' => (string) $id, 'series' => rawurlencode(self::KEY_MO), 'keep' => '1'];
        ob_start();
        (new Admin())->render();
        self::assertStringContainsString('confirm_adopt', (string) ob_get_clean());
    }

    /* ---------------------------------------------------------------- D7 */

    public function testD7TheFeedFollowsGraceAndHeldRuns(): void
    {
        $ics = self::fixture('union-beispielkalender.ics');
        $this->sync($ics, '2026-11-19 00:00');
        $count = static fn () => count(State::occurrences('union')['occurrences']);
        $full = $count();
        $without = (string) preg_replace('/BEGIN:VEVENT\r\nUID:union-brunch-1129@example.*?END:VEVENT\r\n/s', '', $ics);
        $this->sync($without, '2026-11-19 00:15');
        self::assertSame($full, $count(), 'first run without the event: still in the feed (grace)');
        $this->sync($without, '2026-11-19 00:30');
        self::assertSame($full - 1, $count(), 'second run: gone');

        // Held run: the previous list stays.
        $this->sync($ics, '2026-11-19 00:45');
        $gutted = $ics;
        foreach (['union-mittwochsbar-1125@example', 'union-brunch-1129@example', 'union-theater-1127@example', 'union-repaircafe-1205@example', 'union-seniorensport@example', 'union-kinderpiazza@example'] as $uid) {
            $gutted = (string) preg_replace('/BEGIN:VEVENT\r\nUID:' . preg_quote($uid, '/') . '.*?END:VEVENT\r\n/s', '', $gutted);
        }
        $this->sync($gutted, '2026-11-19 01:00');
        $held = $this->sync($gutted, '2026-11-19 01:15');
        self::assertTrue($held->held);
        self::assertSame($full, $count(), 'R-13 also protects the feed');
    }

    /* ---------------------------------------------------------------- D8 */

    /** @param array<string, array{0: int, 1: string, 2?: array<string, string>}> $answers url => [code, body, headers] */
    private function fakeTransport(array $answers): void
    {
        add_filter('pre_http_request', static function ($pre, array $args, string $url) use ($answers) {
            self::assertSame(0, $args['redirection'], 'WordPress follows no redirect');
            if (!isset($answers[$url])) {
                return new \WP_Error('http_request_failed', 'unexpected ' . $url);
            }
            [$code, $body] = $answers[$url];

            return ['headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary($answers[$url][2] ?? []), 'body' => $body, 'response' => ['code' => $code, 'message' => ''], 'cookies' => [], 'filename' => null];
        }, 0, 3);
    }

    public function testD8HttpsIsEnforcedOnEveryHop(): void
    {
        $public = static fn (string $u): bool => str_contains((string) wp_parse_url($u, PHP_URL_HOST), 'example.org');
        $ok = "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n";
        $this->fakeTransport([
            'https://cal.example.org/a.ics' => [200, $ok],
            'https://cal.example.org/moved.ics' => [302, '', ['location' => 'https://cal2.example.org/a.ics']],
            'https://cal2.example.org/a.ics' => [200, $ok],
            'https://cal.example.org/to-http.ics' => [301, '', ['location' => 'http://cal.example.org/a.ics']],
            'https://cal.example.org/to-internal.ics' => [302, '', ['location' => 'https://10.0.0.5/a.ics']],
            'https://cal.example.org/big.ics' => [200, str_repeat('x', HttpFetcher::MAX_BYTES + 1)],
            'https://cal.example.org/big-header.ics' => [200, $ok, ['content-length' => (string) (HttpFetcher::MAX_BYTES + 10)]],
            'https://cal.example.org/loop.ics' => [302, '', ['location' => 'https://cal.example.org/loop.ics']],
        ]);
        $f = new HttpFetcher($public);
        self::assertSame($ok, $f->fetch('https://cal.example.org/a.ics'));
        self::assertSame($ok, $f->fetch('https://cal.example.org/moved.ics'), 'an https redirect to a public host is followed');
        $refused = static function (callable $fn): string {
            try {
                $fn();
            } catch (FetchError $e) {
                return $e->getMessage();
            }
            self::fail('not refused');
        };
        self::assertStringContainsString('https://', $refused(static fn () => $f->fetch('http://cal.example.org/a.ics')));
        self::assertStringContainsString('https://', $refused(static fn () => $f->fetch('https://cal.example.org/to-http.ics')), 'redirect to http');
        self::assertStringContainsString('internes Netz', $refused(static fn () => (new HttpFetcher())->fetch('https://10.0.0.5/a.ics')), 'internal address');
        self::assertStringContainsString('internes Netz', $refused(static fn () => (new HttpFetcher(static fn (string $u): bool => $public($u) && (bool) wp_http_validate_url($u) || str_contains($u, 'cal.example.org')))->fetch('https://cal.example.org/to-internal.ics')), 'redirect to an internal address');
        self::assertStringContainsString('grösser', $refused(static fn () => $f->fetch('https://cal.example.org/big.ics')));
        self::assertStringContainsString('grösser', $refused(static fn () => $f->fetch('https://cal.example.org/big-header.ics')));
        self::assertStringContainsString('zu oft', $refused(static fn () => $f->fetch('https://cal.example.org/loop.ics')));
    }

    /* ---------------------------------------------------------------- D9 */

    public function testD9UninstallRemovesOptionsButKeepsRecords(): void
    {
        global $wpdb;
        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        $this->feedRequest(['source' => 'union']);
        $ids = self::managed();
        self::assertCount(8, $ids);
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'enter-once-sync/enter-once-sync.php');
        }
        include dirname(__DIR__, 2) . '/uninstall.php';
        self::assertSame('0', (string) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'enteronce\\_%' OR option_name LIKE '\\_transient\\_enteronce\\_%'"));
        self::assertFalse(get_option(Options::OPTION), 'the secret address is gone');
        foreach ($ids as $id) {
            self::assertSame('publish', get_post_status($id), 'records stay');
            self::assertSame('1', get_post_meta($id, EventAdapter::META_MANAGED, true), 'with their meta');
        }
    }

    /* ---------------------------------------------------------------- should fix */

    public function testReleaseIsBoundToTheListTheAdministratorSaw(): void
    {
        $ics = self::fixture('union-beispielkalender.ics');
        $this->sync($ics, '2026-11-19 00:00');
        $gutted = $ics;
        foreach (['union-mittwochsbar-1125@example', 'union-brunch-1129@example', 'union-theater-1127@example', 'union-repaircafe-1205@example', 'union-seniorensport@example', 'union-kinderpiazza@example'] as $uid) {
            $gutted = (string) preg_replace('/BEGIN:VEVENT\r\nUID:' . preg_quote($uid, '/') . '.*?END:VEVENT\r\n/s', '', $gutted);
        }
        $this->sync($gutted, '2026-11-19 00:15');
        $this->sync($gutted, '2026-11-19 00:30');
        $seen = (string) State::get('union')['held_hash'];
        // Meanwhile a seventh entry disappears: the list changes.
        $worse = (string) preg_replace('/BEGIN:VEVENT\r\nUID:union-yoga@example.*?END:VEVENT\r\n/s', '', $gutted);
        $this->sync($worse, '2026-11-19 00:35');
        $this->clock->set('2026-11-19 00:40');
        $this->fetcher->set(self::UNION_URL, $worse);
        $refused = $this->runner()->run('union', 'manual', false, $seen);
        self::assertTrue($refused->held);
        self::assertStringContainsString('hat sich seit der Anzeige geändert', (string) $refused->error);
        self::assertCount(8, self::managed('union', 'publish'), 'nothing removed');
        $current = (string) State::get('union')['held_hash'];
        $released = $this->runner()->run('union', 'manual', false, $current);
        self::assertFalse($released->held);
        self::assertSame(7, $released->counts['unpublish']);
    }

    public function testErrorMailsAreRateLimitedByCategory(): void
    {
        $n = new Notifier($this->clock);
        self::assertTrue($n->error('union', 'Der Kalender-Server hat mit Status 502 geantwortet.', 'fetch'));
        self::assertFalse($n->error('union', 'Der Kalender-Server hat mit Status 503 geantwortet.', 'fetch'), 'same category, other number');
        self::assertTrue($n->error('union', 'Die Kalenderdatei ist leer.', 'integrity:empty_text'));
    }

    public function testWizardIgnoresKeysThatChangeOnEverySave(): void
    {
        FieldWizard::reset();
        $draft = (int) wp_insert_post(['post_type' => 'events', 'post_status' => 'draft', 'post_title' => FieldWizard::LEARN_TITLE]);
        $saves = 0;
        // Another plugin (like an SEO plugin) rewrites its own meta on every save.
        $save = static function (array $changes) use ($draft, &$saves): void {
            qtp_standin_apply_form($draft, array_replace(qtp_standin_form_values($draft), $changes));
            update_post_meta($draft, '_seo_last_analysis', (string) ++$saves);
        };
        $save([]);
        $w = new FieldWizard();
        self::assertTrue($w->begin());
        $save([]);
        self::assertTrue($w->compare());
        self::assertSame(['_seo_last_analysis'], FieldWizard::state()['ignore']);
        $save(['qx_a' => '2031-04-23']);
        self::assertTrue($w->compare());
        $save(['qx_b' => '07:45']);
        self::assertTrue($w->compare());
        $save(['qx_c' => '09:05']);
        self::assertTrue($w->compare());
        $save(['qx_d' => '1']);
        self::assertTrue($w->compare(), 'a switch step succeeds although another key changed too: ' . FieldWizard::state()['message']);
        self::assertSame('_qtp_rhythm', Options::get()['field_map']['weekly_flag']['meta']);
        // A step that cannot be learned says so plainly.
        $save([]);
        self::assertFalse($w->compare());
        self::assertStringContainsString('Entwicklerin oder den Entwickler des Tools', FieldWizard::state()['message'] . FieldWizard::developerHint());
    }

    public function testOnlyAdministratorsAssignAnImageToACentre(): void
    {
        $image = FeedTest::attachment('Bild_Test.jpg', null);
        $editor = username_exists('enteronce-editor') ?: wp_create_user('enteronce-editor', wp_generate_password(), 'editor@example.org');
        (new \WP_User((int) $editor))->set_role('editor');
        wp_set_current_user((int) $editor);
        Images::saveField(['ID' => $image], ['enteronce_image_source' => 'union']);
        self::assertSame('', get_post_meta($image, Images::META_SOURCE, true));
        $fields = Images::editField([], get_post($image));
        self::assertStringNotContainsString('<select', $fields['enteronce_image_source']['html']);
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        Images::saveField(['ID' => $image], ['enteronce_image_source' => 'union']);
        self::assertSame('union', get_post_meta($image, Images::META_SOURCE, true));
    }

    public function testPrivatePendingScheduledAndTrashedRecordsAreLeftAloneAndReported(): void
    {
        $ics = self::fixture('union-beispielkalender.ics');
        $this->sync($ics, '2026-11-19 00:00');
        $bar = self::byKey('union:union-mittwochsbar-1125@example');
        $brunch = self::byKey('union:union-brunch-1129@example');
        $theater = self::byKey('union:union-theater-1127@example');
        wp_update_post(['ID' => $bar, 'post_status' => 'private']);
        wp_update_post(['ID' => $brunch, 'post_status' => 'pending']);
        wp_trash_post($theater);
        $changed = str_replace(['SUMMARY:MittwochsBAR im Union', 'SUMMARY:Brunch Atelier Cuisine Globale'], ['SUMMARY:MittwochsBAR neu', 'SUMMARY:Brunch neu'], $ics);
        $this->configure(['digest_frequency' => 'each_run']);
        $this->sync($changed, '2026-11-19 00:15');
        self::assertSame(['private', 'MittwochsBAR im Union'], [get_post_status($bar), get_post_field('post_title', $bar)]);
        self::assertSame(['pending', 'Brunch Atelier Cuisine Globale'], [get_post_status($brunch), get_post_field('post_title', $brunch)]);
        self::assertSame('trash', get_post_status($theater));
        $state = State::get('union');
        $text = implode("\n", $state['skipped']);
        self::assertStringContainsString('privat', $text);
        self::assertStringContainsString('Freigabe', $text);
        self::assertStringContainsString('Papierkorb', $text);
        $digest = end($GLOBALS['enteronce_test_mails']);
        self::assertStringContainsString('Papierkorb', $digest['message'], 'also in the digest');
    }

    public function testStatusPageFeedAddressAndSchedulerLine(): void
    {
        self::assertSame('*/15 * * * * wp --path=/srv/wp/ enteronce sync --quiet', Admin::cronLine(15, '/srv/wp/'));
        self::assertStringStartsWith('*/30 ', Admin::cronLine(30, '/x/'));
        self::assertStringStartsWith('0 */2 * * *', Admin::cronLine(120, '/x/'));
        $plain = static fn (): string => 'http://localhost/?rest_route=/enteronce/v1/events';
        add_filter('rest_url', $plain);
        try {
            parse_str((string) wp_parse_url(Admin::feedUrl('union'), PHP_URL_QUERY), $q);
            self::assertSame(['rest_route' => '/enteronce/v1/events', 'source' => 'union'], $q, 'works with plain permalinks');
        } finally {
            remove_filter('rest_url', $plain);
        }
    }

    public function testTestDoublesAreNotInThePluginCode(): void
    {
        $src = dirname(__DIR__, 2) . '/src/WordPress';
        self::assertFileDoesNotExist($src . '/StaticFetcher.php');
        self::assertFileDoesNotExist($src . '/FixedClock.php');
        self::assertStringContainsString('ENTERONCE_DEV', (string) file_get_contents($src . '/Cli.php'));
    }
}

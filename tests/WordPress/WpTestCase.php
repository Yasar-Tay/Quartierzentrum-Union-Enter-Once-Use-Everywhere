<?php

declare(strict_types=1);

namespace EnterOnce\Tests\WordPress;

use EnterOnce\Tests\WordPress\Support\FixedClock;
use EnterOnce\Tests\WordPress\Support\StaticFetcher;
use EnterOnce\WordPress\EventAdapter;
use EnterOnce\WordPress\Notifier;
use EnterOnce\WordPress\Options;
use EnterOnce\WordPress\Runner;
use EnterOnce\WordPress\RunReport;
use EnterOnce\WordPress\TermResolver;
use PHPUnit\Framework\TestCase;

/**
 * Base for integration tests against the local site. Every test starts from the seeded stand-in
 * (16 centres, Union's hand-entered records) and plugin settings for the source «union».
 */
abstract class WpTestCase extends TestCase
{
    protected const UNION_URL = 'https://fixtures.test/union.ics';
    protected const OTHER_URL = 'https://fixtures.test/other.ics';

    protected FixedClock $clock;
    protected StaticFetcher $fetcher;
    /** @var array<string, int> seeded record ids by slug */
    protected array $seeded = [];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(0);
        $_POST = [];
        $_REQUEST = [];
        $_GET = [];
        self::resetPlugin();
        $this->seeded = qtp_standin_seed(true);
        $this->clock = new FixedClock('2026-11-19 00:00');
        $this->fetcher = new StaticFetcher();
        $GLOBALS['enteronce_test_mails'] = [];
        $this->configure();
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        $_POST = [];
        $_REQUEST = [];
        $_GET = [];
        parent::tearDown();
    }

    public static function resetPlugin(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'enteronce\\_%' OR option_name LIKE '\\_transient\\_enteronce\\_%' OR option_name LIKE '\\_transient\\_timeout\\_enteronce\\_%'");
        wp_cache_flush();
        foreach (get_posts(['post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_enteronce_test']) as $id) {
            wp_delete_post((int) $id, true);
        }
    }

    /**
     * The mapping a tool developer would fill in by hand for the stand-in (test knowledge only;
     * the plugin's code never mentions these names).
     *
     * @return array<string, mixed>
     */
    public static function standinFieldMap(): array
    {
        return [
            'date' => ['meta' => '_qtp_when', 'format' => 'Ymd'],
            'time_start' => ['meta' => '_qtp_t1', 'format' => 'Hi'],
            'time_end' => ['meta' => '_qtp_t2', 'format' => 'Hi'],
            'weekly_flag' => ['meta' => '_qtp_rhythm', 'on' => 'w', 'off' => null],
            'weekday' => ['meta' => '_qtp_wtag', 'format' => 'de2'],
            'cancelled_flag' => ['meta' => '_qtp_storno', 'on' => 'ja', 'off' => 'nein'],
            'vacation_flag' => ['meta' => '_qtp_pause', 'on' => '1', 'off' => null],
            'info_text' => ['meta' => '_qtp_orginfo', 'format' => 'html'],
            'room' => ['none' => true],
        ];
    }

    /** @param array<string, mixed> $overrides top-level settings to change */
    protected function configure(array $overrides = [], bool $dryRun = false, bool $withOther = false): void
    {
        $sources = [[
            'id' => 'union',
            'label' => 'Quartierzentrum Union',
            'ics_url' => self::UNION_URL,
            'location_term' => self::centre('Quartierzentrum Union'),
            'notify_emails' => ['team@example.org'],
            'enabled' => true,
            'dry_run' => $dryRun,
        ]];
        if ($withOther) {
            $sources[] = [
                'id' => 'rheinbogen',
                'label' => 'Quartiertreffpunkt Rheinbogen',
                'ics_url' => self::OTHER_URL,
                'location_term' => self::centre('Quartiertreffpunkt Rheinbogen'),
                'notify_emails' => ['rheinbogen@example.org'],
                'enabled' => true,
                'dry_run' => false,
            ];
        }
        Options::save(array_replace(Options::defaults(), [
            'sources' => $sources,
            'field_map' => self::standinFieldMap(),
            'field_map_verified' => true,
        ], $overrides));
        (new TermResolver())->resolveAll(Options::get());
    }

    protected static function centre(string $name): int
    {
        $t = get_term_by('name', $name, 'location_cat');
        self::assertInstanceOf(\WP_Term::class, $t, $name);

        return (int) $t->term_id;
    }

    protected static function fixture(string $name): string
    {
        $text = file_get_contents(dirname(__DIR__) . '/fixtures/' . $name);
        self::assertIsString($text);

        return $text;
    }

    /** One run with the given calendar text at the given local time. */
    protected function sync(string $ics, string $now, string $source = 'union', bool $releaseHeld = false, string $trigger = 'cron'): RunReport
    {
        $this->clock->set($now);
        $configured = Options::source(Options::get(), $source);
        $this->fetcher->set((string) ($configured['ics_url'] ?? ($source === 'union' ? self::UNION_URL : self::OTHER_URL)), $ics);
        $report = (new Runner($this->clock, $this->fetcher, new Notifier($this->clock)))->run($source, $trigger, $releaseHeld);
        self::assertNotSame(RunReport::ERROR, $report->status, (string) $report->error);
        self::assertNotSame(RunReport::LOCKED, $report->status);

        return $report;
    }

    /** @return list<int> managed record ids of a source, any status */
    protected static function managed(string $source = 'union', ?string $status = null): array
    {
        return array_values(array_filter(
            (new EventAdapter(Options::get(), Options::fieldMap(Options::get()), new TermResolver(), new \EnterOnce\WordPress\Images(Options::get())))->managedIds($source),
            static fn (int $id) => $status === null || get_post_status($id) === $status
        ));
    }

    protected static function byKey(string $key): ?int
    {
        $ids = get_posts(['post_type' => 'events', 'post_status' => EventAdapter::STATUSES, 'meta_key' => EventAdapter::META_KEY, 'meta_value' => $key, 'fields' => 'ids', 'posts_per_page' => 2]);
        self::assertLessThanOrEqual(1, count($ids), "two records with key $key");

        return $ids === [] ? null : (int) $ids[0];
    }

    /**
     * The stand-in's programme page for a centre, parsed.
     *
     * @return list<array{section: string, title: string, day: string, time: string, band: string, id: int}>
     */
    protected static function programme(string $centre = 'Quartierzentrum Union'): array
    {
        $html = qtp_standin_programme_html([self::centre($centre)]);
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $xp = new \DOMXPath($doc);
        $rows = [];
        foreach (['dated' => 'events-simple-programme', 'weekly' => 'events-listing-weekly'] as $section => $class) {
            foreach ($xp->query("//section[contains(@class,'$class')]//div[contains(concat(' ',normalize-space(@class),' '),' qtp-article-preview ')]") as $card) {
                $dates = $xp->query(".//span[contains(@class,'qtp-article-date')]", $card);
                $band = $xp->query(".//div[contains(@class,'qtp-article-cancelled')]//span", $card);
                $rows[] = [
                    'section' => $section,
                    'title' => trim($xp->query('.//h3', $card)->item(0)->textContent),
                    'day' => trim($dates->item(0)?->textContent ?? ''),
                    'time' => trim($dates->item(1)?->textContent ?? ''),
                    'band' => trim($band->item(0)?->textContent ?? ''),
                    'id' => (int) $card->getAttribute('data-post-id'),
                ];
            }
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> rows of the programme whose title matches */
    protected static function programmeRows(string $title, string $centre = 'Quartierzentrum Union'): array
    {
        return array_values(array_filter(self::programme($centre), static fn (array $r) => $r['title'] === $title));
    }

    /** @return array<string, string> every meta value of a post (first value per key) */
    protected static function allMeta(int $id): array
    {
        $out = [];
        foreach ((array) get_post_meta($id) as $k => $v) {
            $out[(string) $k] = (string) ($v[0] ?? '');
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    protected static function mails(): array
    {
        return $GLOBALS['enteronce_test_mails'];
    }

    /** Snapshot of all events and their meta, for "nothing changed" assertions. */
    protected static function snapshotEvents(): array
    {
        $out = [];
        foreach (get_posts(['post_type' => 'events', 'post_status' => EventAdapter::STATUSES, 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC']) as $p) {
            $meta = self::allMeta($p->ID);
            unset($meta['_edit_lock'], $meta['_edit_last']);
            $out[$p->ID] = [$p->post_title, $p->post_status, $p->post_content, $meta, wp_get_object_terms($p->ID, ['topic', 'targetgroup', 'location_cat'], ['fields' => 'ids'])];
        }

        return $out;
    }
}

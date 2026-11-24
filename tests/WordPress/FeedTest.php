<?php

declare(strict_types=1);

namespace EnterOnce\Tests\WordPress;

use EnterOnce\WordPress\FeedController;
use EnterOnce\WordPress\Images;
use EnterOnce\WordPress\Options;
use WP_REST_Request;

/** Plan 4.8 and the feed contract of AGENTS.md. */
final class FeedTest extends WpTestCase
{
    private function request(array $params): \WP_REST_Response|\WP_Error
    {
        add_filter('enteronce_clock', [$this, 'clockFilter']);
        try {
            $r = new WP_REST_Request('GET', '/enteronce/v1/events');
            $r->set_query_params($params);
            $response = rest_do_request($r);
        } finally {
            remove_filter('enteronce_clock', [$this, 'clockFilter']);
        }

        return $response;
    }

    public function clockFilter(): \EnterOnce\WordPress\Clock
    {
        return $this->clock;
    }

    public function testFeedReturnsThe71OccurrencesOfTheSampleCalendar(): void
    {
        $this->configure(['window_months_ahead' => 5]);
        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        $response = $this->request(['source' => 'union', 'from' => '2026-11-19', 'to' => '2027-03-31']);
        self::assertSame(200, $response->get_status());
        $items = $response->get_data();
        self::assertCount(71, $items);
        self::assertSame(['id', 'title', 'start', 'end', 'all_day', 'room', 'location', 'description_html', 'categories', 'tags', 'image', 'cancelled', 'series_id'], array_keys($items[0]));
        $starts = array_map(static fn ($i) => strtotime($i['start']), $items);
        $sorted = $starts;
        sort($sorted);
        self::assertSame($sorted, $starts);
        self::assertSame('union:union-deutschtreff@example:2026-11-19', $items[0]['id']);
        self::assertSame('2026-11-19T15:00:00+01:00', $items[0]['start']);
        self::assertSame('union:union-deutschtreff@example', $items[0]['series_id']);
        self::assertNull($items[0]['image']);
        self::assertStringContainsString('public, max-age=300', $response->get_headers()['Cache-Control']);
        self::assertSame('*', $response->get_headers()['Access-Control-Allow-Origin']);

        // Defaults: from = today (the clock), to = end of the window; never beyond it.
        $this->clock->set('2026-11-19 08:00');
        self::assertCount(71, $this->request(['source' => 'union'])->get_data());
        self::assertCount(71, $this->request(['source' => 'union', 'from' => '2026-11-19', 'to' => '2028-12-31'])->get_data());
        $december = $this->request(['source' => 'union', 'from' => '2026-12-01', 'to' => '2026-12-31'])->get_data();
        self::assertNotEmpty($december);
        foreach ($december as $i) {
            self::assertStringStartsWith('2026-12', $i['start']);
        }
    }

    public function testImagesFollowA15(): void
    {
        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        $own = self::attachment('Offener_Deutschtreff.jpg', 'union');
        $category = self::attachment('Kategorie_Bewegung.jpg', null);
        $default = self::attachment('Standardbild.jpg', null);
        $foreign = self::attachment('Yoga_im_Union.jpg', 'rheinbogen');
        $this->configure(['category_images' => ['Bewegung' => $category], 'default_image_id' => $default]);
        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:15');
        $items = $this->request(['source' => 'union', 'from' => '2026-11-19', 'to' => '2027-02-19'])->get_data();
        $byTitle = [];
        foreach ($items as $i) {
            $byTitle[$i['title']] ??= $i['image'];
        }
        self::assertStringEndsWith('Offener_Deutschtreff.jpg', $byTitle['Offener Deutschtreff']['url'], 'own image by title');
        self::assertStringEndsWith('Kategorie_Bewegung.jpg', $byTitle['Yoga im Union']['url'], 'another centre\'s image is never used');
        self::assertStringEndsWith('Standardbild.jpg', $byTitle['Repair Café']['url']);
        self::assertSame('Offener Deutschtreff', $byTitle['Offener Deutschtreff']['alt']);

        $deutsch = self::byKey('union:union-deutschtreff@example:TH');
        self::assertSame($own, (int) get_post_thumbnail_id($deutsch), 'featured image on the record');
        // An image set by hand is never replaced.
        $hand = self::attachment('Von_Hand.jpg', null);
        set_post_thumbnail(self::byKey('union:union-yoga@example:MO'), $hand);
        $this->sync(str_replace('Sanftes Yoga für alle Niveaus. Matten', 'Sanftes Yoga. Matten', self::fixture('union-beispielkalender.ics')), '2026-11-19 00:30');
        self::assertSame($hand, (int) get_post_thumbnail_id(self::byKey('union:union-yoga@example:MO')));
        unset($foreign);
    }

    public function testFeedSwitchesAndCors(): void
    {
        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        self::assertSame(404, $this->request(['source' => 'unbekannt'])->get_status());
        $this->configure(['public_feed_enabled' => false]);
        self::assertSame(404, $this->request(['source' => 'union'])->get_status());

        self::assertSame(['Access-Control-Allow-Origin' => '*'], FeedController::corsHeaders(Options::defaults(), 'https://preview.example.org'));
        $limited = ['feed_allowed_origins' => ['https://www.example.org']] + Options::defaults();
        self::assertSame(['Access-Control-Allow-Origin' => 'https://www.example.org', 'Vary' => 'Origin'], FeedController::corsHeaders($limited, 'https://www.example.org'));
        self::assertSame(['Vary' => 'Origin'], FeedController::corsHeaders($limited, 'https://other.example.org'));
    }

    public function testFeedIsCachedAndRefreshedAfterARun(): void
    {
        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        $first = $this->request(['source' => 'union', 'from' => '2026-11-25', 'to' => '2026-11-25'])->get_data();
        self::assertSame('MittwochsBAR im Union', $first[0]['title']);
        $changed = str_replace('SUMMARY:MittwochsBAR im Union', 'SUMMARY:MittwochsBAR', self::fixture('union-beispielkalender.ics'));
        $this->sync($changed, '2026-11-19 00:15');
        $second = $this->request(['source' => 'union', 'from' => '2026-11-25', 'to' => '2026-11-25'])->get_data();
        self::assertSame('MittwochsBAR', $second[0]['title'], 'a run invalidates the cache');
    }

    public function testFeedOverHttpFromTheLocalSite(): void
    {
        $this->sync(self::fixture('union-beispielkalender.ics'), '2026-11-19 00:00');
        // A real HTTP request to the local web server (never to an outside host).
        $response = wp_remote_get('http://wordpress/wp-json/enteronce/v1/events?source=union&from=2026-11-25&to=2026-11-29', ['headers' => ['Origin' => 'https://preview.example.org', 'Host' => 'localhost:18471']]);
        if (is_wp_error($response)) {
            self::markTestSkipped('Local web server not reachable from the test container: ' . $response->get_error_message());
        }
        self::assertSame(200, wp_remote_retrieve_response_code($response));
        self::assertSame('*', wp_remote_retrieve_header($response, 'access-control-allow-origin'));
        self::assertSame('', (string) wp_remote_retrieve_header($response, 'access-control-allow-credentials'));
        $items = json_decode(wp_remote_retrieve_body($response), true);
        self::assertIsArray($items);
        self::assertNotEmpty($items);
    }

    /** A test image in the media library, optionally marked for a centre. */
    public static function attachment(string $file, ?string $source): int
    {
        $id = wp_insert_attachment([
            'post_title' => preg_replace('/\.jpg$/', '', $file),
            'post_mime_type' => 'image/jpeg',
            'post_status' => 'inherit',
            'guid' => content_url('uploads/' . $file),
        ], $file);
        update_post_meta($id, '_wp_attached_file', $file);
        update_post_meta($id, '_enteronce_test', '1');
        if ($source !== null) {
            update_post_meta($id, Images::META_SOURCE, $source);
        }

        return (int) $id;
    }
}

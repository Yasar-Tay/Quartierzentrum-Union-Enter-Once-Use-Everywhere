<?php

declare(strict_types=1);

namespace EnterOnce\Tests\WordPress;

use EnterOnce\WordPress\Notifier;
use EnterOnce\WordPress\Secret;
use EnterOnce\WordPress\State;

/** R-12 and plan 4.9: no person's name or address and no ICS address in any output. */
final class PrivacyTest extends WpTestCase
{
    private const PERSONAL = ['Erika', 'Muster', 'Max Beispiel', 'erika.muster', 'max.beispiel', 'programm-kalender', 'Erinnerung'];

    public function testNoPersonDataAnywhere(): void
    {
        $this->configure(['digest_frequency' => 'each_run']);
        $this->sync(self::fixture('attendees.ics'), '2027-01-04 00:00');
        global $wpdb;
        $haystack = [];
        $haystack[] = (string) wp_json_encode($wpdb->get_results("SELECT post_title, post_content, post_excerpt FROM {$wpdb->posts}"));
        $haystack[] = (string) wp_json_encode($wpdb->get_results("SELECT meta_key, meta_value FROM {$wpdb->postmeta}"));
        $haystack[] = (string) wp_json_encode($wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%enteronce%'"));
        $request = new \WP_REST_Request('GET', '/enteronce/v1/events');
        $request->set_query_params(['source' => 'union', 'from' => '2027-01-04', 'to' => '2027-03-31']);
        $feed = rest_do_request($request)->get_data();
        self::assertNotEmpty($feed);
        $haystack[] = (string) wp_json_encode($feed);
        $haystack[] = (string) wp_json_encode(self::mails());
        self::assertNotEmpty(self::mails(), 'a digest was sent');
        $log = WP_CONTENT_DIR . '/debug.log';
        $haystack[] = is_file($log) ? (string) file_get_contents($log) : '';
        $all = implode("\n", $haystack);
        foreach (self::PERSONAL as $needle) {
            self::assertStringNotContainsString($needle, $all, "R-12: «{$needle}» reached an output");
        }
    }

    public function testIcsAddressIsNeverInStateMailsOrFeed(): void
    {
        $secret = 'https://calendar.example.org/ical/secret-token-12345/basic.ics';
        $settings = \EnterOnce\WordPress\Options::get();
        $settings['sources'][0]['ics_url'] = $secret;
        \EnterOnce\WordPress\Options::save($settings);
        $this->fetcher->set($secret, new \EnterOnce\WordPress\FetchError('cURL error 6: Could not resolve host for ' . $secret));
        $report = (new \EnterOnce\WordPress\Runner($this->clock, $this->fetcher, new Notifier($this->clock)))->run('union');
        self::assertSame('error', $report->status);
        $all = (string) wp_json_encode([State::get('union'), self::mails(), $report->toArray()]);
        self::assertStringNotContainsString('secret-token-12345', $all);
        self::assertNotEmpty(self::mails(), 'error mailed at once');
        self::assertSame('https://calendar.example.org/…', Secret::mask($secret));

        // R-32: the same error at most once a day.
        $GLOBALS['enteronce_test_mails'] = [];
        (new \EnterOnce\WordPress\Runner($this->clock, $this->fetcher, new Notifier($this->clock)))->run('union');
        self::assertSame([], self::mails());
        $this->clock->advance('+25 hours');
        (new \EnterOnce\WordPress\Runner($this->clock, $this->fetcher, new Notifier($this->clock)))->run('union');
        self::assertCount(1, self::mails());
    }
}

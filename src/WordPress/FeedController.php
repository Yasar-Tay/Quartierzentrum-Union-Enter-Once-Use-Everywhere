<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

use DateTimeImmutable;
use EnterOnce\Core\Core;
use EnterOnce\Core\Occurrence;
use EnterOnce\Core\Settings as CoreSettings;
use EnterOnce\Core\Window;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Public feed (plan 4.8, format in AGENTS.md):
 * GET /wp-json/enteronce/v1/events?source=<id>&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Served from the stored occurrence list, never from WordPress records. `from` defaults to
 * today, `to` to the end of the sync window, and a request cannot reach beyond the window.
 * Cached for `feed_cache_seconds`. Open to every origin by default (`feed_allowed_origins`).
 */
final class FeedController
{
    public const NAMESPACE = 'enteronce/v1';
    public const ROUTE = '/events';

    public function __construct(private readonly Clock $clock)
    {
    }

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'GET',
            'callback' => [$this, 'handle'],
            'permission_callback' => '__return_true',
            'args' => [
                'source' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key'],
                'from' => ['type' => 'string', 'required' => false, 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                'to' => ['type' => 'string', 'required' => false, 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $settings = Options::get();
        if (empty($settings['public_feed_enabled'])) {
            return new WP_Error('enteronce_feed_off', __('Der öffentliche Feed ist ausgeschaltet.', 'enter-once-sync'), ['status' => 404]);
        }
        $sourceId = (string) $request->get_param('source');
        $source = Options::source($settings, $sourceId);
        if ($source === null) {
            return new WP_Error('enteronce_unknown_source', __('Unbekanntes Zentrum.', 'enter-once-sync'), ['status' => 404]);
        }
        $items = $this->items($sourceId, $request->get_param('from'), $request->get_param('to'), $settings);
        if ($items instanceof WP_Error) {
            return $items;
        }
        $response = new WP_REST_Response($items, 200);
        $seconds = max(0, (int) $settings['feed_cache_seconds']);
        $response->header('Cache-Control', 'public, max-age=' . $seconds);
        foreach (self::corsHeaders($settings, $_SERVER['HTTP_ORIGIN'] ?? null) as $name => $value) {
            $response->header($name, $value);
        }

        return $response;
    }

    /**
     * The feed items for a source and range (also used by tests and the status page).
     *
     * @param array<string, mixed> $settings
     * @return list<array<string, mixed>>|WP_Error
     */
    public function items(string $sourceId, mixed $from, mixed $to, array $settings): array|WP_Error
    {
        $fromGiven = self::date($from);
        $toGiven = self::date($to);
        if ($fromGiven === false || $toGiven === false) {
            return new WP_Error('enteronce_bad_range', __('Ungültiger Zeitraum.', 'enter-once-sync'), ['status' => 400]);
        }
        $stored = State::occurrences($sourceId);
        if (!isset($stored['window']['start'], $stored['window']['end'], $stored['occurrences'])) {
            return [];
        }
        // Review D4: from and to are clamped to the stored window.
        $tz = CoreSettings::timezone();
        $firstDay = (new DateTimeImmutable((string) $stored['window']['start']))->setTimezone($tz)->format('Y-m-d');
        $lastDay = (new DateTimeImmutable((string) $stored['window']['end']))->setTimezone($tz)->modify('-1 second')->format('Y-m-d');
        $fromDay = $fromGiven ?? $this->clock->now()->format('Y-m-d');
        $toDay = $toGiven ?? $lastDay;
        $fromDay = max($fromDay, $firstDay);
        $toDay = min($toDay, $lastDay);
        if ($toDay < $fromDay) {
            return [];
        }
        $range = Window::fromDates($fromDay, $toDay);

        // Review D4: one cache entry per source and data version (the whole list); ranges are filtered in memory.
        $all = $this->allItems($sourceId, $stored, $settings);
        $start = $range->start->getTimestamp();
        $end = $range->end->getTimestamp();

        return array_values(array_filter($all, static fn (array $i) => strtotime((string) $i['start']) < $end && strtotime((string) $i['end']) > $start));
    }

    /**
     * The whole feed of a source, cached once per data version and image settings.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $settings
     * @return list<array<string, mixed>>
     */
    private function allItems(string $sourceId, array $stored, array $settings): array
    {
        $cacheKey = 'enteronce_feed_' . $sourceId;
        $version = State::feedVersion($sourceId) . '|' . md5((string) wp_json_encode([$settings['cancel_mode'], $settings['category_images'], $settings['default_image_id'], $settings['image_mode']]));
        $seconds = max(0, (int) $settings['feed_cache_seconds']);
        if ($seconds > 0) {
            $cached = get_transient($cacheKey);
            if (is_array($cached) && ($cached['version'] ?? null) === $version && is_array($cached['items'] ?? null)) {
                return $cached['items'];
            }
        }
        $occurrences = [];
        foreach ((array) $stored['occurrences'] as $row) {
            try {
                $occurrences[] = Occurrence::fromArray((array) $row);
            } catch (\Throwable) {
                continue;
            }
        }
        $items = (new Core($settings, $sourceId))->feed($occurrences);
        $byId = [];
        foreach ($occurrences as $o) {
            $byId[$o->syncKey] = $o;
        }
        $images = new Images($settings);
        $resolved = [];
        foreach ($items as &$item) {
            $o = $byId[$item['id']] ?? null;
            $cacheId = $item['title'] . '|' . implode(',', $item['categories']);
            if (!array_key_exists($cacheId, $resolved)) {
                $resolved[$cacheId] = Images::feedImage($images->resolve($sourceId, (string) $item['title'], $o?->categories ?? []), (string) $item['title']);
            }
            $item['image'] = $resolved[$cacheId];
        }
        unset($item);
        if ($seconds > 0) {
            set_transient($cacheKey, ['version' => $version, 'items' => $items], $seconds);
        }

        return $items;
    }

    /**
     * A strict YYYY-MM-DD date (no roll-over like 2027-13-45), null when not given, false when invalid.
     */
    public static function date(mixed $value): string|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, CoreSettings::timezone());

        return $d !== false && $d->format('Y-m-d') === $value ? $value : false;
    }

    /**
     * CORS headers for the feed: `*` when allowed for all, else the request's origin if listed.
     *
     * @param array<string, mixed> $settings
     * @return array<string, string>
     */
    public static function corsHeaders(array $settings, ?string $origin): array
    {
        $allowed = array_values(array_filter(array_map('trim', (array) $settings['feed_allowed_origins'])));
        if ($allowed === [] || in_array('*', $allowed, true)) {
            return ['Access-Control-Allow-Origin' => '*'];
        }
        if ($origin !== null && in_array(rtrim($origin, '/'), array_map(static fn ($o) => rtrim($o, '/'), $allowed), true)) {
            return ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin'];
        }

        return ['Vary' => 'Origin'];
    }

    /**
     * WordPress answers REST requests with the request's origin and «credentials: true».
     * For the public feed that is replaced by the headers above.
     */
    public static function filterServe(bool $served, $result, WP_REST_Request $request): bool
    {
        if (str_starts_with($request->get_route(), '/' . self::NAMESPACE . '/')) {
            header_remove('Access-Control-Allow-Credentials');
            foreach (self::corsHeaders(Options::get(), $_SERVER['HTTP_ORIGIN'] ?? null) as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        return $served;
    }
}

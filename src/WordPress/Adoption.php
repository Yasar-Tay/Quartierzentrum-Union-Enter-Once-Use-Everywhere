<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

use EnterOnce\Core\GermanDate;
use EnterOnce\Core\TargetRecord;
use WP_Error;

/**
 * Adopting existing weekly offers (plan 4.7, A-14). A manual pairing with help:
 *  - candidates are calendar weekly series with the same weekday and overlapping time;
 *  - nothing is ever paired automatically; one offer can be paired with one series only;
 *  - before the first write a revision (and a full copy in `_enteronce_backup`) is stored;
 *  - title and text stay as on the site by default (`adopt_keep_text`, per record `_enteronce_keep_text`).
 * Adoption writes only the `_enteronce_*` meta; the next run then maintains time, flags and terms.
 */
final class Adoption
{
    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings, private readonly FieldMap $map)
    {
    }

    public function ready(): bool
    {
        return $this->map->entry('weekly_flag') !== null && $this->map->entry('time_start') !== null && $this->map->entry('time_end') !== null;
    }

    /**
     * The centre's weekly offers that are not managed.
     *
     * @return list<array{id: int, title: string, weekday: ?int, time_start: ?string, time_end: ?string, status: string}>
     */
    public function siteOffers(string $sourceId): array
    {
        $source = Options::source($this->settings, $sourceId);
        $term = (int) ($source['location_term'] ?? 0);
        if ($source === null || $term === 0 || !$this->ready()) {
            return [];
        }
        $ids = get_posts([
            'post_type' => EventAdapter::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'orderby' => 'title',
            'order' => 'ASC',
            'tax_query' => [['taxonomy' => TermResolver::TAX_LOCATION, 'terms' => [$term]]],
            'meta_query' => [['key' => EventAdapter::META_MANAGED, 'compare' => 'NOT EXISTS']],
        ]);
        $out = [];
        foreach ($ids as $id) {
            $v = $this->map->decode((int) $id);
            if (!$v['weekly_flag']) {
                continue;
            }
            $weekday = $v['weekday'];
            if ($weekday === null && is_string($v['date'])) {
                $weekday = (int) (new \DateTimeImmutable($v['date']))->format('N');
            }
            $out[] = [
                'id' => (int) $id,
                'title' => html_entity_decode(get_the_title((int) $id), ENT_QUOTES, 'UTF-8'),
                'weekday' => $weekday,
                'time_start' => $v['time_start'],
                'time_end' => $v['time_end'],
                'status' => (string) get_post_status((int) $id),
            ];
        }

        return $out;
    }

    /**
     * Calendar weekly series of the last run that no managed record uses yet.
     *
     * @return list<array<string, mixed>>
     */
    public function series(string $sourceId): array
    {
        $out = [];
        foreach ((array) (State::get($sourceId)['weekly_series'] ?? []) as $s) {
            if (!self::keyInUse($sourceId, (string) $s['key'])) {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * Same weekday and overlapping time. Never decides; only lists.
     *
     * @param array<string, mixed> $offer
     * @param list<array<string, mixed>> $series
     * @return list<array<string, mixed>>
     */
    public static function candidates(array $offer, array $series): array
    {
        if ($offer['weekday'] === null || $offer['time_start'] === null) {
            return [];
        }

        return array_values(array_filter($series, static fn (array $s) => (int) $s['weekday'] === (int) $offer['weekday']
            && $s['time_start'] !== null
            && self::overlap((string) $offer['time_start'], (string) ($offer['time_end'] ?? $offer['time_start']), (string) $s['time_start'], (string) $s['time_end'])));
    }

    public static function overlap(string $s1, string $e1, string $s2, string $e2): bool
    {
        $m = static fn (string $t): int => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
        [$a1, $b1, $a2, $b2] = [$m($s1), $m($e1), $m($s2), $m($e2)];
        $b1 = $b1 <= $a1 ? $b1 + 1440 : $b1;
        $b2 = $b2 <= $a2 ? $b2 + 1440 : $b2;

        return $a1 < $b2 && $a2 < $b1;
    }

    /**
     * Field-by-field comparison of a site offer and a calendar series.
     *
     * @param array<string, mixed> $series
     * @return list<array{label: string, site: string, calendar: string, same: bool, kept: bool}>
     */
    public function compare(int $postId, array $series, bool $keepText): array
    {
        $v = $this->map->decode($postId);
        $names = static fn (string $tax): string => implode(', ', array_map(static fn ($n) => html_entity_decode((string) $n, ENT_QUOTES, 'UTF-8'), (array) wp_get_object_terms($postId, $tax, ['fields' => 'names'])));
        $siteText = trim(wp_strip_all_tags((string) get_post_field('post_content', $postId)));
        $calendarText = trim(wp_strip_all_tags((string) $series['body']));
        $source = null;
        foreach ($this->settings['sources'] as $s) {
            if (str_starts_with((string) $series['key'], $s['id'] . ':')) {
                $source = $s;
            }
        }
        $centre = $source !== null ? get_term((int) $source['location_term'], TermResolver::TAX_LOCATION) : null;
        // Third column: true = text (kept with «Text der Website behalten»), null = always kept on the site,
        // false = taken from the calendar. Terms are kept when the calendar gives none (review D5).
        $rows = [
            [__('Titel', 'enter-once-sync'), html_entity_decode(get_the_title($postId), ENT_QUOTES, 'UTF-8'), (string) $series['title'], true],
            [__('Text', 'enter-once-sync'), wp_trim_words($siteText, 25), wp_trim_words($calendarText, 25), true],
            [__('Wochentag', 'enter-once-sync'), $v['weekday'] !== null ? GermanDate::weekdayName((int) $v['weekday']) : '–', GermanDate::weekdayName((int) $series['weekday']), false],
            [__('Beginn', 'enter-once-sync'), (string) ($v['time_start'] ?? '–'), (string) ($series['time_start'] ?? '–'), false],
            [__('Ende', 'enter-once-sync'), (string) ($v['time_end'] ?? '–'), (string) ($series['time_end'] ?? '–'), false],
            [__('Themen', 'enter-once-sync'), $names(TermResolver::TAX_TOPIC), implode(', ', (array) $series['topics']), (array) $series['topics'] === [] ? null : false],
            [__('Zielgruppen', 'enter-once-sync'), $names(TermResolver::TAX_TARGETGROUP), implode(', ', (array) $series['target_groups']), (array) $series['target_groups'] === [] ? null : false],
            [__('Zentren', 'enter-once-sync'), $names(TermResolver::TAX_LOCATION), $centre instanceof \WP_Term ? html_entity_decode($centre->name, ENT_QUOTES, 'UTF-8') . ' ' . __('(wird ergänzt; andere Zentren bleiben)', 'enter-once-sync') : '–', null],
        ];
        $out = [];
        foreach ($rows as [$label, $site, $calendar, $kind]) {
            $out[] = ['label' => $label, 'site' => $site, 'calendar' => $calendar, 'same' => $site === $calendar, 'kept' => $kind === null || ($kind === true && $keepText)];
        }

        return $out;
    }

    /**
     * Adopt one offer for one series.
     *
     * @param bool $publish for an offer that is not published: publish it with the next run; otherwise
     *                      its status (draft, pending, private) is kept (review D6)
     */
    public function adopt(int $postId, string $sourceId, string $seriesKey, bool $keepText, \DateTimeImmutable $now, bool $publish = false): bool|WP_Error
    {
        if (get_post_type($postId) !== EventAdapter::POST_TYPE) {
            return new WP_Error('enteronce_adopt', __('Eintrag nicht gefunden.', 'enter-once-sync'));
        }
        if (get_post_meta($postId, EventAdapter::META_MANAGED, true) === '1') {
            return new WP_Error('enteronce_adopt', __('Dieses Angebot ist bereits übernommen.', 'enter-once-sync'));
        }
        $series = null;
        foreach ($this->series($sourceId) as $s) {
            if ($s['key'] === $seriesKey) {
                $series = $s;
            }
        }
        if ($series === null) {
            return new WP_Error('enteronce_adopt', __('Diese Serie ist nicht verfügbar oder bereits mit einem anderen Angebot verbunden.', 'enter-once-sync'));
        }
        $offer = null;
        foreach ($this->siteOffers($sourceId) as $o) {
            if ($o['id'] === $postId) {
                $offer = $o;
            }
        }
        if ($offer === null || self::candidates($offer, [$series]) === []) {
            return new WP_Error('enteronce_adopt', __('Wochentag oder Zeit passen nicht zu dieser Serie.', 'enter-once-sync'));
        }

        // Copy before the first write: a revision, and a full copy for fields revisions do not keep.
        if (post_type_supports(EventAdapter::POST_TYPE, 'revisions')) {
            wp_save_post_revision($postId);
        }
        $meta = [];
        foreach ((array) get_post_meta($postId) as $k => $values) {
            if (!str_starts_with((string) $k, '_enteronce_') && !str_starts_with((string) $k, '_edit_')) {
                $meta[(string) $k] = $values;
            }
        }
        $terms = [];
        foreach ([TermResolver::TAX_TOPIC, TermResolver::TAX_TARGETGROUP, TermResolver::TAX_LOCATION] as $tax) {
            $terms[$tax] = wp_get_object_terms($postId, $tax, ['fields' => 'ids']);
        }
        $status = (string) get_post_status($postId);
        $backup = ['time' => $now->format(DATE_ATOM), 'title' => get_post_field('post_title', $postId, 'raw'), 'content' => get_post_field('post_content', $postId, 'raw'), 'status' => $status, 'meta' => $meta, 'terms' => $terms];

        $own = [
            EventAdapter::META_BACKUP => (string) wp_json_encode($backup),
            EventAdapter::META_SOURCE => $sourceId,
            EventAdapter::META_KEY => $seriesKey,
            EventAdapter::META_KIND => TargetRecord::WEEKLY_OFFER,
            EventAdapter::META_HASH => '',
            EventAdapter::META_MISSING => '0',
            EventAdapter::META_KEEP_TEXT => $keepText ? '1' : '0',
            EventAdapter::META_WEEKDAY => (string) $series['weekday'],
            EventAdapter::META_TIME_START => (string) $series['time_start'],
            EventAdapter::META_ADOPTED => $now->format(DATE_ATOM),
        ];
        if ($status !== 'publish' && !$publish) {
            $own[EventAdapter::META_KEEP_STATUS] = $status;
        }
        // The managed flag last: a half-written adoption is not a managed record.
        $own[EventAdapter::META_MANAGED] = '1';
        foreach ($own as $k => $v) {
            update_post_meta($postId, $k, wp_slash($v));
        }

        return true;
    }

    /**
     * Adopted records of a source (for «Übernahme rückgängig machen»).
     *
     * @return list<array{id: int, title: string, key: string, adopted: string}>
     */
    public function adopted(string $sourceId): array
    {
        $ids = get_posts([
            'post_type' => EventAdapter::POST_TYPE,
            'post_status' => EventAdapter::STATUSES,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_query' => [
                ['key' => EventAdapter::META_SOURCE, 'value' => $sourceId],
                ['key' => EventAdapter::META_ADOPTED, 'compare' => 'EXISTS'],
                ['key' => EventAdapter::META_BACKUP, 'compare' => 'EXISTS'],
            ],
        ]);

        return array_map(static fn ($id) => [
            'id' => (int) $id,
            'title' => html_entity_decode(get_the_title((int) $id), ENT_QUOTES, 'UTF-8'),
            'key' => (string) get_post_meta((int) $id, EventAdapter::META_KEY, true),
            'adopted' => (string) get_post_meta((int) $id, EventAdapter::META_ADOPTED, true),
        ], $ids);
    }

    /**
     * «Übernahme rückgängig machen» (review D5): restores title, text, status, every field and the terms
     * from `_enteronce_backup` and removes all `_enteronce_*` meta, so the record is free again.
     */
    public static function undo(int $postId): bool|WP_Error
    {
        $backup = json_decode((string) get_post_meta($postId, EventAdapter::META_BACKUP, true), true);
        if (!is_array($backup) || !isset($backup['meta'], $backup['terms'])) {
            return new WP_Error('enteronce_undo', __('Für diesen Eintrag gibt es keine Kopie von vor der Übernahme.', 'enter-once-sync'));
        }
        $post = ['ID' => $postId, 'post_title' => (string) $backup['title'], 'post_content' => (string) $backup['content'], 'post_status' => (string) $backup['status']];
        $result = wp_update_post(wp_slash($post), true);
        if (is_wp_error($result)) {
            return $result;
        }
        $saved = (array) $backup['meta'];
        foreach (array_keys((array) get_post_meta($postId)) as $key) {
            $key = (string) $key;
            if (!str_starts_with($key, '_enteronce_') && !str_starts_with($key, '_edit_') && !array_key_exists($key, $saved)) {
                delete_post_meta($postId, $key);
            }
        }
        foreach ($saved as $key => $values) {
            delete_post_meta($postId, (string) $key);
            foreach ((array) $values as $value) {
                add_post_meta($postId, (string) $key, wp_slash(maybe_unserialize($value)));
            }
        }
        foreach ((array) $backup['terms'] as $tax => $ids) {
            if (taxonomy_exists((string) $tax)) {
                wp_set_object_terms($postId, array_map('intval', (array) $ids), (string) $tax);
            }
        }
        foreach (array_keys((array) get_post_meta($postId)) as $key) {
            if (str_starts_with((string) $key, '_enteronce_')) {
                delete_post_meta($postId, (string) $key);
            }
        }

        return true;
    }

    public static function keyInUse(string $sourceId, string $key): bool
    {
        return get_posts([
            'post_type' => EventAdapter::POST_TYPE,
            'post_status' => EventAdapter::STATUSES,
            'posts_per_page' => 1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_query' => [
                ['key' => EventAdapter::META_KEY, 'value' => $key],
                ['key' => EventAdapter::META_SOURCE, 'value' => $sourceId],
                ['key' => EventAdapter::META_MANAGED, 'value' => '1'],
            ],
        ]) !== [];
    }
}

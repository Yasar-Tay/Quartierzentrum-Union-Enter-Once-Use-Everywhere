<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

use DateTimeImmutable;
use EnterOnce\Core\Action;
use EnterOnce\Core\MovedDateNote;
use EnterOnce\Core\SiteRecord;
use EnterOnce\Core\TargetRecord;
use EnterOnce\Core\Settings as CoreSettings;

/**
 * All writes to the event tool go through this class (A-03). It writes the tool's fields only
 * through the learned `field_map`, taxonomy terms by stored IDs, and its own `_enteronce_*` meta.
 *
 * R-10: the tool's own series generation is never triggered. Writes run with empty request
 * superglobals, so a save handler that reads the edit form finds nothing to act on.
 */
final class EventAdapter
{
    public const POST_TYPE = 'events';

    // Plan 4.4.
    public const META_SOURCE = '_enteronce_source';
    public const META_KEY = '_enteronce_key';
    public const META_HASH = '_enteronce_hash';
    public const META_MANAGED = '_enteronce_managed';
    public const META_MISSING = '_enteronce_missing_runs';
    // What the core needs back (R-07, R-08, R-27) and A-14, A-15.
    public const META_KIND = '_enteronce_kind';
    public const META_START = '_enteronce_start';
    public const META_END = '_enteronce_end';
    public const META_WEEKDAY = '_enteronce_weekday';
    public const META_TIME_START = '_enteronce_time_start';
    public const META_FLAGS = '_enteronce_flags';
    public const META_KEEP_TEXT = '_enteronce_keep_text';
    public const META_IMAGE_AUTO = '_enteronce_image_auto';
    public const META_BACKUP = '_enteronce_backup';
    public const META_ADOPTED = '_enteronce_adopted';
    /** Fingerprint of the field map a record was written with (review D1). */
    public const META_MAP = '_enteronce_map';
    /** Adopted non-published record: the status the administrator kept (review D6). */
    public const META_KEEP_STATUS = '_enteronce_keep_status';

    public const STATUSES = ['publish', 'draft', 'pending', 'private', 'future', 'trash'];

    /** @var list<string> term names without a term, collected during a run */
    public array $missingTerms = [];
    /** @var list<string> German notes about records that could not be written */
    public array $skipped = [];

    /** @param array<string, mixed> $settings */
    public function __construct(
        private readonly array $settings,
        private readonly FieldMap $map,
        private readonly TermResolver $terms,
        private readonly Images $images,
    ) {
    }

    /** @return list<SiteRecord> managed records of the source (R-04) */
    public function siteRecords(string $sourceId): array
    {
        $out = [];
        foreach ($this->managedIds($sourceId) as $id) {
            $out[] = $this->siteRecord($id);
        }

        return $out;
    }

    /** @return list<int> */
    public function managedIds(string $sourceId): array
    {
        return array_map('intval', get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => self::STATUSES,
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
            'meta_query' => [
                ['key' => self::META_MANAGED, 'value' => '1'],
                ['key' => self::META_SOURCE, 'value' => $sourceId],
            ],
        ]));
    }

    /**
     * A managed record as the core sees it.
     *
     * Status: «publish» → publish; «draft» and «trash» → draft (a trashed record is never touched,
     * see apply()). «private», «pending» and «future» are reported as publish so the core never
     * plans to republish them; apply() leaves them alone. An adopted record whose status the
     * administrator kept (`_enteronce_keep_status`) counts as publish while it has that status.
     * Hash: empty when the record was written with another field map (review D1), so a relearned
     * map rewrites every record once.
     */
    public function siteRecord(int $id): SiteRecord
    {
        $m = static fn (string $k): string => (string) get_post_meta($id, $k, true);
        $date = static function (string $v): ?DateTimeImmutable {
            if ($v === '') {
                return null;
            }
            try {
                return (new DateTimeImmutable($v))->setTimezone(CoreSettings::timezone());
            } catch (\Exception) {
                return null;
            }
        };
        $flags = json_decode($m(self::META_FLAGS), true);
        $postStatus = (string) get_post_status($id);
        $kept = $m(self::META_KEEP_STATUS);
        $status = in_array($postStatus, ['publish', 'private', 'pending', 'future'], true) || ($kept !== '' && $kept === $postStatus)
            ? TargetRecord::PUBLISH
            : TargetRecord::DRAFT;
        $weekday = $m(self::META_WEEKDAY);
        $hash = $m(self::META_HASH);
        if ($hash !== '' && $m(self::META_MAP) !== $this->mapFingerprint()) {
            $hash = '';
        }

        return new SiteRecord(
            $m(self::META_KEY),
            $m(self::META_KIND) !== '' ? $m(self::META_KIND) : TargetRecord::DATED_EVENT,
            $hash !== '' ? $hash : null,
            $status,
            (int) $m(self::META_MISSING),
            $m(self::META_SOURCE),
            $m(self::META_MANAGED) === '1',
            $m(self::META_KEEP_TEXT) === '1',
            is_array($flags) ? array_map('boolval', $flags) : [],
            $date($m(self::META_START)),
            $date($m(self::META_END)),
            html_entity_decode(get_the_title($id), ENT_QUOTES, 'UTF-8'),
            $weekday !== '' ? (int) $weekday : null,
            $m(self::META_TIME_START) !== '' ? $m(self::META_TIME_START) : null,
            $id,
        );
    }

    /** Fingerprint of the field map the records are written with (stored per record). */
    public function mapFingerprint(): string
    {
        return md5((string) wp_json_encode($this->map->toArray()));
    }

    /**
     * Statuses a person set by hand that the sync must not change (review: private, pending,
     * scheduled), and the bin. Returns the German reason, or null when the record may be written.
     */
    private function untouchable(int $postId): ?string
    {
        $status = (string) get_post_status($postId);
        $kept = (string) get_post_meta($postId, self::META_KEEP_STATUS, true);
        if ($kept !== '' && $kept === $status) {
            return null;
        }
        $title = html_entity_decode((string) get_post_field('post_title', $postId, 'raw'), ENT_QUOTES, 'UTF-8');

        return match ($status) {
            'trash' => sprintf(__('«%s» liegt im Papierkorb und wird deshalb nicht mehr abgeglichen. Bitte wiederherstellen oder endgültig löschen.', 'enter-once-sync'), $title),
            'private' => sprintf(__('«%s» ist privat gestellt und wird deshalb nicht verändert.', 'enter-once-sync'), $title),
            'pending' => sprintf(__('«%s» wartet auf Freigabe und wird deshalb nicht verändert.', 'enter-once-sync'), $title),
            'future' => sprintf(__('«%s» ist zur Veröffentlichung geplant und wird deshalb nicht verändert.', 'enter-once-sync'), $title),
            default => null,
        };
    }

    /**
     * Applies one action of a plan that is not a dry run.
     *
     * @param array<string, list<string>> $categoriesByKey calendar categories per target key (for images)
     * @return int|null the post ID when the record itself changed
     */
    public function apply(Action $action, string $sourceId, array $categoriesByKey = []): ?int
    {
        $postId = $action->siteId !== null ? (int) $action->siteId : null;
        if ($postId !== null && get_post_type($postId) !== self::POST_TYPE) {
            return null;
        }
        if ($postId !== null && in_array($action->type, [Action::UPDATE, Action::UNPUBLISH], true) && ($why = $this->untouchable($postId)) !== null) {
            $this->skipped[] = $why;

            return null;
        }
        switch ($action->type) {
            case Action::CREATE:
                $id = $this->write(null, $action->target, $sourceId, false, TargetRecord::PUBLISH, $action->key, $action->hash, $categoriesByKey[$action->key] ?? []);
                Integrations::afterCreate($id, (string) $this->settings['wpml_mode']);

                return $id;
            case Action::UPDATE:
                $keep = get_post_meta($postId, self::META_KEEP_TEXT, true) === '1';
                $id = $this->write($postId, $action->target, $sourceId, $keep, $action->status, $action->key, $action->hash, $categoriesByKey[$action->key] ?? []);
                Integrations::afterUpdate($id, (string) $this->settings['wpml_mode']);

                return $id;
            case Action::UNPUBLISH:
                $this->guarded(static fn () => wp_update_post(['ID' => $postId, 'post_status' => 'draft'], true));
                update_post_meta($postId, self::META_MISSING, (string) $action->missingRuns);

                return $postId;
            default: // keep, hold
                if ($postId !== null && (int) get_post_meta($postId, self::META_MISSING, true) !== $action->missingRuns) {
                    update_post_meta($postId, self::META_MISSING, (string) $action->missingRuns);
                }
                if ($postId !== null && ($why = $this->untouchable($postId)) !== null && get_post_status($postId) === 'trash') {
                    $this->skipped[] = $why;

                    return null;
                }
                // An image uploaded after the record was written (A-15).
                if ($postId !== null && $action->target !== null && !$action->target->past && get_post_status($postId) === 'publish') {
                    return $this->image($postId, $sourceId, (string) $action->target->fields['title'], $categoriesByKey[$action->key] ?? []) ? $postId : null;
                }

                return null;
        }
    }

    /**
     * Writes a target onto a new or existing record. Also used for the wizard's verification draft.
     *
     * A new record is inserted as a draft that already carries `_enteronce_managed`, `_enteronce_source`
     * and `_enteronce_key` (same `wp_insert_post` call); fields and terms follow; publishing is the last
     * step (review D2). A run that stops in between leaves a managed draft that the next run finishes.
     *
     * @param list<string> $categories calendar categories (image fallback)
     */
    public function write(?int $postId, TargetRecord $t, string $sourceId, bool $keepText, string $status, string $key, ?string $hash = null, array $categories = []): int
    {
        $f = $t->fields;
        $weekly = $t->kind === TargetRecord::WEEKLY_OFFER;
        $title = sanitize_text_field((string) $f['title']);
        $post = ['post_type' => self::POST_TYPE];
        if ($postId === null || !$keepText) {
            $post['post_title'] = $title;
            $post['post_content'] = (string) $f['body'];
        } elseif ($weekly) {
            // Kept text (A-14): only the moved-date note on top is maintained (R-24).
            $post['post_content'] = MovedDateNote::apply((string) get_post_field('post_content', $postId, 'raw'), $f['note'] ?? null);
        }
        if ($postId === null) {
            $post['post_status'] = 'draft';
            $post['meta_input'] = [
                self::META_MANAGED => '1',
                self::META_SOURCE => $sourceId,
                self::META_KEY => $key,
                self::META_KIND => $t->kind,
                self::META_HASH => '',
                self::META_MISSING => '0',
            ];
        } else {
            $post['ID'] = $postId;
        }
        $result = $this->guarded(static fn () => $postId === null ? wp_insert_post(wp_slash($post), true) : wp_update_post(wp_slash($post), true));
        if (is_wp_error($result) || (int) $result === 0) {
            throw new \RuntimeException(is_wp_error($result) ? $result->get_error_message() : 'wp_insert_post failed');
        }
        $id = (int) $result;

        // Tool fields through the field map.
        $values = [
            'date' => $weekly ? $f['first_date'] : $f['date'],
            'time_start' => $f['time_start'],
            'time_end' => $f['time_end'],
            'weekly_flag' => $weekly,
            'cancelled_flag' => $weekly ? (bool) $f['abgesagt'] : (bool) $f['cancelled'],
            'vacation_flag' => $weekly && (bool) $f['ferienpause'],
            'room' => sanitize_text_field((string) $f['room']),
        ];
        if ($weekly) {
            $values['weekday'] = (int) $f['weekday'];
        }
        if ($postId === null || !$keepText) {
            $info = sanitize_text_field((string) $f['location']);
            $values['info_text'] = ($this->map->entry('info_text')['format'] ?? 'text') === 'html' && $info !== '' ? '<p>' . esc_html($info) . '</p>' : $info;
        }
        foreach ($this->map->encode($values) as $meta => $value) {
            if ($value === null) {
                delete_post_meta($id, $meta);
            } else {
                update_post_meta($id, $meta, wp_slash($value));
            }
        }

        // Taxonomies (review D5): the centre is added, never replacing other centres; topics and
        // target groups are set only when the calendar provides some, otherwise the record keeps its own.
        $topics = $this->terms->ids(TermResolver::TAX_TOPIC, (array) $f['topics'], $this->missingTerms);
        $groups = $this->terms->ids(TermResolver::TAX_TARGETGROUP, (array) $f['target_groups'], $this->missingTerms);
        if ($topics !== [] && taxonomy_exists(TermResolver::TAX_TOPIC)) {
            wp_set_object_terms($id, $topics, TermResolver::TAX_TOPIC);
        }
        if ($groups !== [] && taxonomy_exists(TermResolver::TAX_TARGETGROUP)) {
            wp_set_object_terms($id, $groups, TermResolver::TAX_TARGETGROUP);
        }
        $location = (int) ($f['location_term'] ?? 0);
        if ($location > 0 && taxonomy_exists(TermResolver::TAX_LOCATION)) {
            wp_set_object_terms($id, [$location], TermResolver::TAX_LOCATION, true);
        }

        // Own meta.
        $own = [
            self::META_MANAGED => '1',
            self::META_SOURCE => $sourceId,
            self::META_KEY => $key,
            self::META_KIND => $t->kind,
            self::META_HASH => $hash ?? $t->hashFor($keepText),
            self::META_MAP => $this->mapFingerprint(),
            self::META_MISSING => '0',
            self::META_START => $t->start->format(DATE_ATOM),
            self::META_END => $t->end?->format(DATE_ATOM) ?? '',
            self::META_FLAGS => (string) wp_json_encode($weekly
                ? ['ferienpause' => (bool) $f['ferienpause'], 'abgesagt' => (bool) $f['abgesagt']]
                : ['cancelled' => (bool) $f['cancelled']]),
        ];
        if ($weekly) {
            $own[self::META_WEEKDAY] = (string) $f['weekday'];
            $own[self::META_TIME_START] = (string) ($f['time_start'] ?? '');
        }
        foreach ($own as $k => $v) {
            update_post_meta($id, $k, wp_slash($v));
        }

        $this->image($id, $sourceId, $title, $categories);

        // Status last. An adopted record whose status the administrator kept stays as it is.
        $kept = (string) get_post_meta($id, self::META_KEEP_STATUS, true);
        $final = $status === TargetRecord::PUBLISH && $kept !== '' ? $kept : $status;
        if (get_post_status($id) !== $final) {
            $this->guarded(static fn () => wp_update_post(['ID' => $id, 'post_status' => $final], true));
        }

        return $id;
    }

    /**
     * A-15: set the featured image unless one was set by hand (any image the plugin did not set).
     *
     * @return bool whether the image changed
     */
    private function image(int $postId, string $sourceId, string $title, array $categories): bool
    {
        $image = $this->images->resolve($sourceId, $title, $categories);
        if ($image === null) {
            return false;
        }
        $current = (int) get_post_thumbnail_id($postId);
        $auto = (int) get_post_meta($postId, self::META_IMAGE_AUTO, true);
        if ($current !== 0 && $current !== $auto) {
            return false;
        }
        if ($current === $image) {
            return false;
        }
        set_post_thumbnail($postId, $image);
        update_post_meta($postId, self::META_IMAGE_AUTO, (string) $image);

        return true;
    }

    /**
     * Runs a write with empty request data and the marker `enteronce_writing` (R-10).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function guarded(callable $fn): mixed
    {
        $post = $_POST;
        $request = $_REQUEST;
        $_POST = [];
        $_REQUEST = [];
        $GLOBALS['enteronce_writing'] = true;
        try {
            return $fn();
        } finally {
            $_POST = $post;
            $_REQUEST = $request;
            unset($GLOBALS['enteronce_writing']);
        }
    }
}

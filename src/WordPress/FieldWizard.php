<?php

declare(strict_types=1);

namespace EnterOnce\WordPress;

use DateTimeImmutable;
use EnterOnce\Core\Settings as CoreSettings;
use EnterOnce\Core\TargetRecord;

/**
 * The field-mapping wizard of plan section 4.7: it learns by comparison.
 *
 *  1. The administrator creates a draft «Enter Once Lernentwurf» in the tool's own edit screen.
 *     The wizard records all stored fields of that draft.
 *  2. For each field the administrator changes exactly that value to a given sample and saves.
 *     The wizard compares before and after and learns the field's name and value format.
 *  3. The wizard writes a verification draft «Enter Once Prüfentwurf». The administrator checks it
 *     in the edit screen and on the public page; only the confirmation sets `field_map_verified`.
 *
 * Learned entries are saved into `field_map` step by step (unverified, so the sync stays in dry
 * run, R-11). «Neu lernen» clears the map. The wizard never knows any field name in advance.
 */
final class FieldWizard
{
    public const OPTION = 'enteronce_wizard';
    public const LEARN_TITLE = 'Enter Once Lernentwurf';
    public const TEST_TITLE = 'Enter Once Prüfentwurf';

    public const SAMPLE_DATE = '2031-04-23';
    public const SAMPLE_START = '07:45';
    public const SAMPLE_END = '09:05';
    public const SAMPLE_WEEKDAY = 7;
    public const MARK_INFO = 'Enter-Once-Info-4711';
    public const MARK_ROOM = 'Enter-Once-Raum-4711';

    /** Appended when a step cannot be learned. */
    public static function developerHint(): string
    {
        return __('Lässt sich dieser Schritt auch nach «Ausgangszustand neu erfassen» nicht lernen, speichert das Tool das Feld vermutlich nicht als gewöhnliches Beitragsfeld (Post-Meta). Dann braucht es die Entwicklerin oder den Entwickler des Tools.', 'enter-once-sync');
    }

    /** Fields the tool may lack: «Dieses Feld gibt es nicht». */
    public const OPTIONAL = ['weekday', 'info_text', 'room'];

    /** Test draft values (step 3). */
    public const TEST_VALUES = [
        'date' => '2031-05-14', 'time_start' => '18:30', 'time_end' => '20:00', 'weekly_flag' => true, 'weekday' => 3,
        'cancelled_flag' => false, 'vacation_flag' => true, 'info_text' => 'Prüfraum, Musterstrasse 1, 4000 Basel', 'room' => 'Prüfraum',
    ];

    /** @return array{step: string, post_id: int, snapshot: array<string, string>, test_post_id: int, message: string, ok: bool, ignore: list<string>} */
    public static function state(): array
    {
        $s = get_option(self::OPTION, []);
        $s = is_array($s) ? $s : [];

        return [
            'step' => (string) ($s['step'] ?? 'start'),
            'post_id' => (int) ($s['post_id'] ?? 0),
            'snapshot' => (array) ($s['snapshot'] ?? []),
            'test_post_id' => (int) ($s['test_post_id'] ?? 0),
            'message' => (string) ($s['message'] ?? ''),
            'ok' => (bool) ($s['ok'] ?? true),
            'ignore' => array_values(array_map('strval', (array) ($s['ignore'] ?? []))),
        ];
    }

    /** @param array<string, mixed> $state */
    private static function save(array $state): void
    {
        update_option(self::OPTION, $state, false);
    }

    /** German instruction for a step. */
    public static function instruction(string $step): string
    {
        return match ($step) {
            'start' => sprintf(__('Legen Sie im Veranstaltungs-Tool eine neue Veranstaltung an. Titel: «%s». Füllen Sie sonst nichts aus, lassen Sie alle Schalter aus, und speichern Sie sie als Entwurf. Klicken Sie dann auf «Entwurf gespeichert – weiter».', 'enter-once-sync'), self::LEARN_TITLE),
            'noise' => __('Speichern Sie den Lernentwurf im Bearbeitungsbildschirm noch einmal, ohne etwas zu ändern. So erkennt der Assistent Felder, die andere Plugins bei jedem Speichern selbst ändern; diese Felder werden danach nicht beachtet.', 'enter-once-sync'),
            'date' => __('Setzen Sie im Lernentwurf das Datum auf den 23.04.2031 und speichern Sie. Ändern Sie nichts anderes.', 'enter-once-sync'),
            'time_start' => __('Setzen Sie den Beginn auf 07:45 Uhr und speichern Sie.', 'enter-once-sync'),
            'time_end' => __('Setzen Sie das Ende auf 09:05 Uhr und speichern Sie.', 'enter-once-sync'),
            'weekly_flag' => __('Schalten Sie «Wöchentliches Angebot» ein und speichern Sie.', 'enter-once-sync'),
            'weekday' => __('Wählen Sie als Wochentag «Sonntag» und speichern Sie. Hat das Tool kein Feld für den Wochentag, klicken Sie auf «Dieses Feld gibt es nicht».', 'enter-once-sync'),
            'cancelled_flag' => __('Schalten Sie «abgesagt» ein und speichern Sie.', 'enter-once-sync'),
            'vacation_flag' => __('Schalten Sie «abgesagt» wieder aus, schalten Sie «Ferienpause» ein und speichern Sie.', 'enter-once-sync'),
            'info_text' => sprintf(__('Schreiben Sie in das Feld «Organisation und Information» nur den Text «%s» und speichern Sie. Gibt es dieses Feld nicht, klicken Sie auf «Dieses Feld gibt es nicht».', 'enter-once-sync'), self::MARK_INFO),
            'room' => sprintf(__('Hat das Tool ein Feld für den Raum? Dann schreiben Sie dort «%s» und speichern Sie. Sonst klicken Sie auf «Dieses Feld gibt es nicht».', 'enter-once-sync'), self::MARK_ROOM),
            'verify' => __('Alle Felder sind gelernt. Klicken Sie auf «Prüfentwurf anlegen». Öffnen Sie den Prüfentwurf danach im Bearbeitungsbildschirm des Tools und in der Vorschau, und vergleichen Sie mit der Liste unten.', 'enter-once-sync'),
            'done' => __('Die Feldzuordnung ist geprüft. Der Abgleich darf jetzt schreiben, sobald der Probelauf des Zentrums ausgeschaltet ist.', 'enter-once-sync'),
            default => '',
        };
    }

    public static function label(string $field): string
    {
        return match ($field) {
            'date' => __('Datum', 'enter-once-sync'),
            'time_start' => __('Beginn', 'enter-once-sync'),
            'time_end' => __('Ende', 'enter-once-sync'),
            'weekly_flag' => __('Wöchentliches Angebot', 'enter-once-sync'),
            'weekday' => __('Wochentag', 'enter-once-sync'),
            'cancelled_flag' => __('abgesagt', 'enter-once-sync'),
            'vacation_flag' => __('Ferienpause', 'enter-once-sync'),
            'info_text' => __('Organisation und Information', 'enter-once-sync'),
            'room' => __('Raum', 'enter-once-sync'),
            default => $field,
        };
    }

    /** «Neu lernen»: clears the map and starts again. */
    public static function reset(): void
    {
        $settings = Options::get();
        $settings['field_map'] = [];
        $settings['field_map_verified'] = false;
        Options::save($settings);
        self::save(['step' => 'start', 'post_id' => 0, 'snapshot' => [], 'test_post_id' => 0, 'message' => '', 'ok' => true, 'ignore' => []]);
    }

    /** Step 1: find the learning draft and record its fields. */
    public function begin(): bool
    {
        $ids = get_posts([
            'post_type' => EventAdapter::POST_TYPE,
            'post_status' => ['draft', 'pending', 'auto-draft'],
            'title' => self::LEARN_TITLE,
            'posts_per_page' => 1,
            'orderby' => 'ID',
            'order' => 'DESC',
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);
        if ($ids === []) {
            return $this->fail(sprintf(__('Kein Entwurf mit dem Titel «%s» gefunden. Bitte zuerst im Veranstaltungs-Tool anlegen und als Entwurf speichern.', 'enter-once-sync'), self::LEARN_TITLE));
        }
        $settings = Options::get();
        $settings['field_map'] = [];
        $settings['field_map_verified'] = false;
        Options::save($settings);
        $id = (int) $ids[0];
        self::save(['step' => 'noise', 'post_id' => $id, 'snapshot' => self::snapshot($id), 'test_post_id' => 0, 'ignore' => [],
            'message' => sprintf(__('Lernentwurf gefunden (ID %d). Alle gespeicherten Felder wurden erfasst.', 'enter-once-sync'), $id), 'ok' => true]);

        return true;
    }

    /** Step 2: compare the draft with the last snapshot and learn the current field. */
    public function compare(): bool
    {
        $s = self::state();
        $field = $s['step'];
        if ($field === 'noise' && get_post($s['post_id']) !== null) {
            // Second baseline: keys that change on a save without any edit belong to other plugins.
            $after = self::snapshot($s['post_id']);
            $noisy = array_keys(self::changes($s['snapshot'], $after, new FieldMap([])));
            $s['ignore'] = $noisy;
            $s['snapshot'] = $after;
            $s['step'] = FieldMap::FIELDS[0];
            $s['message'] = $noisy === []
                ? __('Zweiter Ausgangszustand erfasst. Keine Felder ändern sich von selbst.', 'enter-once-sync')
                : sprintf(__('Zweiter Ausgangszustand erfasst. Diese Felder ändern sich bei jedem Speichern von selbst und werden nicht beachtet: %s', 'enter-once-sync'), implode(', ', $noisy));
            $s['ok'] = true;
            self::save($s);

            return true;
        }
        if (!in_array($field, FieldMap::FIELDS, true) || get_post($s['post_id']) === null) {
            return $this->fail(__('Der Assistent ist nicht bei einem Feldschritt. Bitte neu beginnen.', 'enter-once-sync'));
        }
        $before = $s['snapshot'];
        $after = self::snapshot($s['post_id']);
        $map = Options::fieldMap(Options::get());
        $changed = array_diff_key(self::changes($before, $after, $map), array_flip($s['ignore']));
        if ($changed === []) {
            return $this->fail(__('Seit dem letzten Schritt hat sich kein gespeichertes Feld verändert. Haben Sie im Bearbeitungsbildschirm gespeichert?', 'enter-once-sync'));
        }

        $entry = match ($field) {
            'date' => self::learnValue($changed, static fn (string $v) => FieldFormats::matchDate($v, self::SAMPLE_DATE)),
            'time_start' => self::learnValue($changed, static fn (string $v) => FieldFormats::matchTime($v, self::SAMPLE_START)),
            'time_end' => self::learnValue($changed, static fn (string $v) => FieldFormats::matchTime($v, self::SAMPLE_END)),
            'weekday' => self::learnValue($changed, static fn (string $v) => FieldFormats::matchWeekday($v, self::SAMPLE_WEEKDAY)),
            'info_text' => self::learnText($changed, self::MARK_INFO),
            'room' => self::learnText($changed, self::MARK_ROOM),
            default => self::learnFlag($changed, $field, $map),
        };
        if (is_string($entry)) {
            return $this->fail($entry . ' ' . self::describe($changed) . ' ' . self::developerHint());
        }
        // Review D1: a key already learned for another value field is never learned twice
        // (only switches may share one stored field).
        $conflict = self::conflict($field, $entry, $map);
        if ($conflict !== null) {
            return $this->fail(sprintf(
                __('Das geänderte Feld %1$s ist bereits als «%2$s» gelernt. Vermutlich wurde der falsche Wert geändert. Stellen Sie «%2$s» zurück, klicken Sie auf «Ausgangszustand neu erfassen» und ändern Sie nur «%3$s».', 'enter-once-sync'),
                $entry['meta'],
                self::label($conflict),
                self::label($field)
            ));
        }
        $companion = '_' . $entry['meta'];
        if (isset($after[$companion]) && str_starts_with($after[$companion], 'field_')) {
            $entry['companion'] = [$companion => $after[$companion]];
        }
        $this->learn($field, $entry, $after, sprintf(__('«%s» gelernt.', 'enter-once-sync'), self::label($field)));

        return true;
    }

    /** «Dieses Feld gibt es nicht» (optional fields only). */
    public function skip(): bool
    {
        $s = self::state();
        if (!in_array($s['step'], self::OPTIONAL, true)) {
            return $this->fail(__('Dieses Feld ist nötig und kann nicht übersprungen werden.', 'enter-once-sync'));
        }
        $this->learn($s['step'], ['none' => true], $s['snapshot'], sprintf(__('«%s»: im Tool nicht vorhanden.', 'enter-once-sync'), self::label($s['step'])));

        return true;
    }

    /** After a failed comparison: take the current state of the draft as the new starting point. */
    public function resnapshot(): bool
    {
        $s = self::state();
        if (get_post($s['post_id']) === null) {
            return $this->fail(__('Der Lernentwurf existiert nicht mehr. Bitte neu beginnen.', 'enter-once-sync'));
        }
        $s['snapshot'] = self::snapshot($s['post_id']);
        $s['message'] = __('Ausgangszustand neu erfasst. Ändern Sie jetzt nur den verlangten Wert.', 'enter-once-sync');
        $s['ok'] = true;
        self::save($s);

        return true;
    }

    /** Step 3: the wizard writes one draft itself, through the same adapter the sync uses. */
    public function writeTestDraft(): int
    {
        $s = self::state();
        $settings = Options::get();
        $map = Options::fieldMap($settings);
        $v = self::TEST_VALUES;
        $tz = CoreSettings::timezone();
        $target = new TargetRecord(
            TargetRecord::WEEKLY_OFFER,
            'wizard:test',
            'wizard',
            'wizard-test',
            'wizard-test',
            TargetRecord::DRAFT,
            null,
            false,
            new DateTimeImmutable($v['date'] . ' ' . $v['time_start'], $tz),
            new DateTimeImmutable('2031-06-25 ' . $v['time_end'], $tz),
            [
                'title' => self::TEST_TITLE,
                'body' => '<p>' . esc_html__('Dieser Entwurf wurde vom Enter-Once-Assistenten geschrieben. Er dient nur der Prüfung der Feldzuordnung und wird nach der Bestätigung in den Papierkorb gelegt.', 'enter-once-sync') . '</p>',
                'weekday' => $v['weekday'],
                'time_start' => $v['time_start'],
                'time_end' => $v['time_end'],
                'all_day' => false,
                'room' => $v['room'],
                'location' => $v['info_text'],
                'topics' => [],
                'target_groups' => [],
                'ferienpause' => $v['vacation_flag'],
                'abgesagt' => $v['cancelled_flag'],
                'note' => null,
                'first_date' => $v['date'],
                'last_date' => '2031-06-25',
                'location_term' => null,
            ],
        );
        $adapter = new EventAdapter($settings, $map, new TermResolver(), new Images(['image_mode' => 'none']));
        $id = $adapter->write($s['test_post_id'] > 0 && get_post($s['test_post_id']) ? $s['test_post_id'] : null, $target, 'wizard', false, 'draft', 'wizard:test');
        // The test draft is not a managed record of any centre.
        foreach ([EventAdapter::META_MANAGED, EventAdapter::META_SOURCE, EventAdapter::META_KEY, EventAdapter::META_HASH, EventAdapter::META_MISSING] as $k) {
            delete_post_meta($id, $k);
        }
        $s['test_post_id'] = $id;
        $s['message'] = __('Prüfentwurf angelegt. Bitte im Tool öffnen und prüfen.', 'enter-once-sync');
        $s['ok'] = true;
        self::save($s);

        return $id;
    }

    /**
     * What the wizard reads back from the test draft through the learned map (self-check).
     *
     * @return array<string, array{expected: mixed, actual: mixed, same: bool}>
     */
    public static function selfCheck(int $postId): array
    {
        $map = Options::fieldMap(Options::get());
        $actual = $map->decode($postId);
        $out = [];
        foreach (self::TEST_VALUES as $field => $expected) {
            if ($map->entry($field) === null) {
                continue;
            }
            $a = $actual[$field];
            if ($field === 'info_text' && is_string($a)) {
                $a = trim(html_entity_decode(wp_strip_all_tags($a), ENT_QUOTES, 'UTF-8'));
            }
            $out[$field] = ['expected' => $expected, 'actual' => $a, 'same' => $a === $expected];
        }

        return $out;
    }

    /** True when every learned field of the test draft reads back as written. */
    public static function selfCheckPasses(int $postId): bool
    {
        $check = self::selfCheck($postId);
        if ($check === []) {
            return false;
        }
        foreach ($check as $row) {
            if (!$row['same']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Review D1: the field that already uses one of the entry's keys, or null. Switches may share
     * a key with each other, nothing else may.
     *
     * @param array<string, mixed> $entry
     */
    public static function conflict(string $field, array $entry, FieldMap $map): ?string
    {
        $keys = [(string) $entry['meta']];
        foreach ((array) ($entry['also'] ?? []) as $also) {
            $keys[] = (string) ($also['meta'] ?? '');
        }
        $isFlag = in_array($field, FieldMap::FLAGS, true);
        foreach (FieldMap::FIELDS as $other) {
            if ($other === $field || ($isFlag && in_array($other, FieldMap::FLAGS, true))) {
                continue;
            }
            $e = $map->entry($other);
            if ($e === null) {
                continue;
            }
            $otherKeys = [(string) $e['meta']];
            foreach ((array) ($e['also'] ?? []) as $also) {
                $otherKeys[] = (string) ($also['meta'] ?? '');
            }
            if (array_intersect($keys, $otherKeys) !== []) {
                return $other;
            }
        }

        return null;
    }

    /** «Alles stimmt – Zuordnung bestätigen». */
    public function confirm(): bool
    {
        $s = self::state();
        if ($s['step'] !== 'verify' || $s['test_post_id'] === 0) {
            return $this->fail(__('Bitte zuerst den Prüfentwurf anlegen und prüfen.', 'enter-once-sync'));
        }
        $settings = Options::get();
        if (!Options::fieldMap($settings)->isComplete()) {
            return $this->fail(__('Die Feldzuordnung ist unvollständig.', 'enter-once-sync'));
        }
        // Review D1: only a test draft that still exists and reads back exactly as written confirms the map.
        $status = get_post_status($s['test_post_id']);
        if ($status === false || $status === 'trash') {
            return $this->fail(__('Der Prüfentwurf existiert nicht mehr. Bitte legen Sie ihn neu an.', 'enter-once-sync'));
        }
        if (!self::selfCheckPasses($s['test_post_id'])) {
            return $this->fail(__('Die Selbstprüfung zeigt Abweichungen. Die Zuordnung kann so nicht bestätigt werden. Klicken Sie auf «Neu lernen».', 'enter-once-sync'));
        }
        $settings['field_map_verified'] = true;
        Options::save($settings);
        foreach ([$s['test_post_id'], $s['post_id']] as $id) {
            if ($id > 0 && get_post($id)) {
                wp_trash_post($id);
            }
        }
        self::save(['step' => 'done', 'post_id' => 0, 'snapshot' => [], 'test_post_id' => 0, 'message' => __('Feldzuordnung bestätigt. Lern- und Prüfentwurf liegen im Papierkorb.', 'enter-once-sync'), 'ok' => true]);

        return true;
    }

    /** @param array<string, mixed> $entry */
    private function learn(string $field, array $entry, array $snapshot, string $message): void
    {
        $settings = Options::get();
        $map = is_array($settings['field_map']) ? $settings['field_map'] : [];
        $map[$field] = $entry;
        $settings['field_map'] = $map;
        $settings['field_map_verified'] = false;
        Options::save($settings);
        $s = self::state();
        $index = array_search($field, FieldMap::FIELDS, true);
        $next = FieldMap::FIELDS[(int) $index + 1] ?? 'verify';
        $s['step'] = $next;
        $s['snapshot'] = $snapshot;
        $s['message'] = $message;
        $s['ok'] = true;
        self::save($s);
    }

    private function fail(string $message): bool
    {
        $s = self::state();
        $s['message'] = $message;
        $s['ok'] = false;
        self::save($s);

        return false;
    }

    /** @return array<string, string> every stored field of a record (first value per key), without WordPress's own bookkeeping */
    public static function snapshot(int $postId): array
    {
        wp_cache_delete($postId, 'post_meta');
        $out = [];
        foreach ((array) get_post_meta($postId) as $key => $values) {
            $key = (string) $key;
            if (str_starts_with($key, '_edit_') || str_starts_with($key, '_wp_') || str_starts_with($key, '_enteronce_') || in_array($key, ['_encloseme', '_pingme', '_thumbnail_id'], true)) {
                continue;
            }
            $out[$key] = (string) ($values[0] ?? '');
        }
        ksort($out);

        return $out;
    }

    /**
     * Keys that changed, minus changes that are explained by fields already learned
     * (for example «abgesagt» switched back off in the Ferienpause step).
     *
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @return array<string, array{0: ?string, 1: ?string}> key => [before, after]
     */
    public static function changes(array $before, array $after, FieldMap $map): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            if ($b !== $a) {
                $out[$key] = [$b, $a];
            }
        }
        foreach (FieldMap::FLAGS as $flag) {
            $e = $map->entry($flag);
            if ($e === null || !array_key_exists($e['meta'], $out)) {
                continue;
            }
            // A learned switch that was turned back off is not the change we are looking for.
            if ($out[$e['meta']][1] === $e['off']) {
                unset($out[$e['meta']]);
            }
        }

        return $out;
    }

    /**
     * @param array<string, array{0: ?string, 1: ?string}> $changed
     * @param callable(string): list<string> $formats
     * @return array<string, mixed>|string entry or German error
     */
    private static function learnValue(array $changed, callable $formats): array|string
    {
        $hits = [];
        foreach ($changed as $key => [, $after]) {
            if ($after === null) {
                continue;
            }
            $f = $formats($after);
            if ($f !== []) {
                $hits[] = ['meta' => $key, 'format' => $f[0]];
            }
        }
        if ($hits === []) {
            return __('Kein geändertes Feld enthält den verlangten Wert in einem bekannten Format.', 'enter-once-sync');
        }
        $entry = $hits[0];
        if (count($hits) > 1) {
            $entry['also'] = array_slice($hits, 1);
        }

        return $entry;
    }

    /**
     * @param array<string, array{0: ?string, 1: ?string}> $changed
     * @return array<string, mixed>|string
     */
    private static function learnText(array $changed, string $marker): array|string
    {
        $hits = [];
        foreach ($changed as $key => [, $after]) {
            if ($after !== null && str_contains($after, $marker)) {
                $hits[] = ['meta' => $key, 'format' => wp_strip_all_tags($after) !== $after ? 'html' : 'text'];
            }
        }
        if (count($hits) !== 1) {
            return $hits === []
                ? sprintf(__('Kein geändertes Feld enthält den Text «%s».', 'enter-once-sync'), $marker)
                : sprintf(__('Mehrere Felder enthalten den Text «%s».', 'enter-once-sync'), $marker);
        }

        return $hits[0];
    }

    /**
     * @param array<string, array{0: ?string, 1: ?string}> $changed
     * @return array<string, mixed>|string
     */
    private static function learnFlag(array $changed, string $field, FieldMap $map): array|string
    {
        if (count($changed) !== 1) {
            return __('Es hat sich mehr als ein Feld verändert. Bitte nur den einen Schalter umstellen.', 'enter-once-sync');
        }
        $key = (string) array_key_first($changed);
        [$before, $after] = $changed[$key];
        // Two flags in one stored value (for example one status field): «off» is the other flag's off.
        foreach (FieldMap::FLAGS as $other) {
            $e = $map->entry($other);
            if ($other !== $field && $e !== null && $e['meta'] === $key) {
                $before = $e['off'];
            }
        }

        return ['meta' => $key, 'on' => $after, 'off' => $before];
    }

    /** @param array<string, array{0: ?string, 1: ?string}> $changed */
    private static function describe(array $changed): string
    {
        if ($changed === []) {
            return '';
        }
        $parts = [];
        foreach (array_slice($changed, 0, 6, true) as $key => [$b, $a]) {
            $parts[] = sprintf('%s: «%s» → «%s»', $key, $b ?? '–', $a ?? '–');
        }

        return __('Geänderte Felder:', 'enter-once-sync') . ' ' . implode('; ', $parts);
    }
}

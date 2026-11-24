<?php
/**
 * QTP stand-in: imitates the association's event tool (plan section 3.3) on the local test site.
 *
 * Storage (deliberately odd, and never mentioned by the plugin):
 *   date              _qtp_when     "Ymd"              e.g. 20261129
 *   start / end time  _qtp_t1/_t2   "Hi"               e.g. 0900
 *   weekly offer      _qtp_rhythm   "w" when on        NOT STORED AT ALL when off
 *   weekday           _qtp_wtag     "Mo" … "So"        German two letters
 *   cancelled         _qtp_storno   "ja" / "nein"      always stored
 *   holiday break     _qtp_pause    "1" when on        NOT STORED AT ALL when off
 *   sidebar info      _qtp_orginfo  HTML text («Organisation und Information»)
 * There is no room field.
 *
 * The edit screen saves only when its own nonce is posted, like most hand-written tools, so
 * programmatic writes (wp_insert_post from a plugin) never pass through the form handler.
 * The box «Serien- und Mehrfachtermine» generates independent copies once on save (R-10 test).
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

const QTP_STANDIN_WEEKDAYS = ['Mo' => 'Montag', 'Di' => 'Dienstag', 'Mi' => 'Mittwoch', 'Do' => 'Donnerstag', 'Fr' => 'Freitag', 'Sa' => 'Samstag', 'So' => 'Sonntag'];

require_once __DIR__ . '/inc/seed.php';

add_action('init', static function (): void {
    register_post_type('events', [
        'label' => 'Veranstaltungen',
        'labels' => ['name' => 'Veranstaltungen', 'singular_name' => 'Veranstaltung', 'add_new_item' => 'Neue Veranstaltung'],
        'public' => true,
        'show_in_rest' => false,
        'has_archive' => false,
        'rewrite' => ['slug' => 'events'],
        'supports' => ['title', 'editor', 'thumbnail', 'revisions'],
        'menu_icon' => 'dashicons-calendar-alt',
    ]);
    register_post_type('locations', [
        'label' => 'Standorte',
        'public' => true,
        'show_in_rest' => false,
        'rewrite' => ['slug' => 'locations'],
        'supports' => ['title', 'editor', 'thumbnail'],
    ]);
    register_post_type('news', [
        'label' => 'News',
        'public' => true,
        'show_in_rest' => false,
        'rewrite' => ['slug' => 'news'],
        'supports' => ['title', 'editor', 'thumbnail'],
    ]);
    register_taxonomy('topic', ['events'], ['label' => 'Themen', 'hierarchical' => true, 'show_admin_column' => true]);
    register_taxonomy('targetgroup', ['events'], ['label' => 'Zielgruppen', 'hierarchical' => true, 'show_admin_column' => true]);
    register_taxonomy('location_cat', ['events', 'locations'], ['label' => 'Zentren', 'hierarchical' => true, 'show_admin_column' => true]);
    register_taxonomy('district', ['locations'], ['label' => 'Quartiere', 'hierarchical' => true]);

    add_rewrite_rule('^programm/?$', 'index.php?qtp_programm=1', 'top');
});

add_filter('query_vars', static function (array $vars): array {
    $vars[] = 'qtp_programm';

    return $vars;
});

add_filter('template_include', static function (string $template): string {
    if (get_query_var('qtp_programm')) {
        return __DIR__ . '/programm.php';
    }

    return $template;
});

/* ------------------------------------------------------------------ edit screen */

add_action('add_meta_boxes_events', static function (): void {
    add_meta_box('qtp_daten', 'Veranstaltungsdaten', 'qtp_standin_box_data', 'events', 'normal', 'high');
    add_meta_box('qtp_serie', 'Serien- und Mehrfachtermine', 'qtp_standin_box_series', 'events', 'normal', 'default');
    add_meta_box('qtp_info', 'Organisation und Information', 'qtp_standin_box_info', 'events', 'side', 'default');
});

function qtp_standin_box_data(WP_Post $post): void
{
    wp_nonce_field('qtp_standin_save', 'qtp_standin_nonce');
    $when = (string) get_post_meta($post->ID, '_qtp_when', true);
    $date = strlen($when) === 8 ? substr($when, 0, 4) . '-' . substr($when, 4, 2) . '-' . substr($when, 6, 2) : '';
    $t = static function (string $v): string {
        return strlen($v) === 4 ? substr($v, 0, 2) . ':' . substr($v, 2, 2) : '';
    };
    $wtag = (string) get_post_meta($post->ID, '_qtp_wtag', true);
    echo '<p><label>Datum <input type="date" name="qx_a" value="' . esc_attr($date) . '"></label></p>';
    echo '<p><label>Beginn <input type="time" name="qx_b" value="' . esc_attr($t((string) get_post_meta($post->ID, '_qtp_t1', true))) . '"></label> ';
    echo '<label>Ende <input type="time" name="qx_c" value="' . esc_attr($t((string) get_post_meta($post->ID, '_qtp_t2', true))) . '"></label></p>';
    echo '<p><label><input type="checkbox" name="qx_d" value="1"' . checked(get_post_meta($post->ID, '_qtp_rhythm', true), 'w', false) . '> Wöchentliches Angebot</label> ';
    echo '<label>Wochentag <select name="qx_e"><option value="">–</option>';
    foreach (QTP_STANDIN_WEEKDAYS as $code => $name) {
        echo '<option value="' . esc_attr($code) . '"' . selected($wtag, $code, false) . '>' . esc_html($name) . '</option>';
    }
    echo '</select></label></p>';
    echo '<p><label><input type="checkbox" name="qx_f" value="1"' . checked(get_post_meta($post->ID, '_qtp_storno', true), 'ja', false) . '> abgesagt</label> ';
    echo '<label><input type="checkbox" name="qx_g" value="1"' . checked(get_post_meta($post->ID, '_qtp_pause', true), '1', false) . '> Ferienpause</label></p>';
}

function qtp_standin_box_series(WP_Post $post): void
{
    echo '<p><label>Wiederholung <select name="qx_rep"><option value="">keine</option><option value="weekly">wöchentlich</option></select></label> ';
    echo '<label>bis <input type="date" name="qx_rep_until"></label></p>';
    echo '<p><label>Zusätzliche Termine (ein Datum pro Zeile, JJJJ-MM-TT)<br><textarea name="qx_extra" rows="3"></textarea></label></p>';
}

function qtp_standin_box_info(WP_Post $post): void
{
    echo '<textarea name="qx_h" rows="5" style="width:100%">' . esc_textarea((string) get_post_meta($post->ID, '_qtp_orginfo', true)) . '</textarea>';
}

add_action('save_post_events', static function (int $postId): void {
    if (!isset($_POST['qtp_standin_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['qtp_standin_nonce'])), 'qtp_standin_save')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (!current_user_can('edit_post', $postId)) {
        return;
    }
    $form = wp_unslash($_POST);
    qtp_standin_apply_form($postId, is_array($form) ? $form : []);
    qtp_standin_generate_series($postId, is_array($form) ? $form : []);
});

/**
 * What the edit screen does with its form values. Tests call it to play the administrator
 * who changes a value in the edit screen and saves.
 *
 * @param array<string, mixed> $form qx_a date (Y-m-d), qx_b/qx_c time (H:i), qx_d weekly (set = on),
 *                                   qx_e weekday code, qx_f cancelled (set = on), qx_g holiday break (set = on), qx_h info text
 */
function qtp_standin_apply_form(int $postId, array $form): void
{
    $date = (string) ($form['qx_a'] ?? '');
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        update_post_meta($postId, '_qtp_when', $m[1] . $m[2] . $m[3]);
    } else {
        delete_post_meta($postId, '_qtp_when');
    }
    foreach (['qx_b' => '_qtp_t1', 'qx_c' => '_qtp_t2'] as $field => $key) {
        $v = (string) ($form[$field] ?? '');
        if (preg_match('/^(\d{2}):(\d{2})$/', $v, $m)) {
            update_post_meta($postId, $key, $m[1] . $m[2]);
        } else {
            delete_post_meta($postId, $key);
        }
    }
    if (!empty($form['qx_d'])) {
        update_post_meta($postId, '_qtp_rhythm', 'w');
    } else {
        delete_post_meta($postId, '_qtp_rhythm');
    }
    $wtag = (string) ($form['qx_e'] ?? '');
    if (isset(QTP_STANDIN_WEEKDAYS[$wtag])) {
        update_post_meta($postId, '_qtp_wtag', $wtag);
    } else {
        delete_post_meta($postId, '_qtp_wtag');
    }
    update_post_meta($postId, '_qtp_storno', !empty($form['qx_f']) ? 'ja' : 'nein');
    if (!empty($form['qx_g'])) {
        update_post_meta($postId, '_qtp_pause', '1');
    } else {
        delete_post_meta($postId, '_qtp_pause');
    }
    update_post_meta($postId, '_qtp_orginfo', wp_kses_post((string) ($form['qx_h'] ?? '')));
}

/** Current form values of a record, as the edit screen would show them. @return array<string, string> */
function qtp_standin_form_values(int $postId): array
{
    $when = (string) get_post_meta($postId, '_qtp_when', true);
    $t = static fn (string $v): string => strlen($v) === 4 ? substr($v, 0, 2) . ':' . substr($v, 2, 2) : '';
    $form = [
        'qx_a' => strlen($when) === 8 ? substr($when, 0, 4) . '-' . substr($when, 4, 2) . '-' . substr($when, 6, 2) : '',
        'qx_b' => $t((string) get_post_meta($postId, '_qtp_t1', true)),
        'qx_c' => $t((string) get_post_meta($postId, '_qtp_t2', true)),
        'qx_e' => (string) get_post_meta($postId, '_qtp_wtag', true),
        'qx_h' => (string) get_post_meta($postId, '_qtp_orginfo', true),
    ];
    if (get_post_meta($postId, '_qtp_rhythm', true) === 'w') {
        $form['qx_d'] = '1';
    }
    if (get_post_meta($postId, '_qtp_storno', true) === 'ja') {
        $form['qx_f'] = '1';
    }
    if (get_post_meta($postId, '_qtp_pause', true) === '1') {
        $form['qx_g'] = '1';
    }

    return $form;
}

/** «Serien- und Mehrfachtermine»: generates independent copies once, like the real tool. */
function qtp_standin_generate_series(int $postId, array $form): void
{
    static $running = false;
    if ($running) {
        return;
    }
    $running = true;
    try {
        qtp_standin_generate_series_once($postId, $form);
    } finally {
        $running = false;
    }
}

function qtp_standin_generate_series_once(int $postId, array $form): void
{
    $dates = [];
    if (($form['qx_rep'] ?? '') === 'weekly' && !empty($form['qx_rep_until']) && !empty($form['qx_a'])) {
        $d = new DateTimeImmutable((string) $form['qx_a']);
        $until = new DateTimeImmutable((string) $form['qx_rep_until']);
        for ($d = $d->modify('+7 days'); $d <= $until; $d = $d->modify('+7 days')) {
            $dates[] = $d->format('Y-m-d');
        }
    }
    foreach (preg_split('/\R/', (string) ($form['qx_extra'] ?? '')) ?: [] as $line) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($line))) {
            $dates[] = trim($line);
        }
    }
    if ($dates === []) {
        return;
    }
    $post = get_post($postId);
    foreach ($dates as $date) {
        $copy = wp_insert_post([
            'post_type' => 'events',
            'post_status' => $post->post_status,
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
        ]);
        $values = qtp_standin_form_values($postId);
        $values['qx_a'] = $date;
        qtp_standin_apply_form((int) $copy, $values);
        update_post_meta((int) $copy, '_qtp_generated_from', $postId);
        foreach (['topic', 'targetgroup', 'location_cat'] as $tax) {
            wp_set_object_terms((int) $copy, wp_get_object_terms($postId, $tax, ['fields' => 'ids']), $tax);
        }
    }
}

/* ------------------------------------------------------------------ programme page */

/**
 * The programme page for the given centres, in the markup of docs/dev/programme-page-notes.md.
 * All published records; dated events by date and time, weekly offers by weekday and time.
 *
 * @param list<int> $termIds location_cat term IDs; empty = all centres
 */
function qtp_standin_programme_html(array $termIds): string
{
    $base = ['post_type' => 'events', 'post_status' => 'publish', 'posts_per_page' => -1, 'no_found_rows' => true];
    if ($termIds !== []) {
        $base['tax_query'] = [['taxonomy' => 'location_cat', 'terms' => $termIds]];
    }
    $dated = get_posts($base + ['meta_query' => [['key' => '_qtp_rhythm', 'compare' => 'NOT EXISTS']]]);
    $weekly = get_posts($base + ['meta_query' => [['key' => '_qtp_rhythm', 'value' => 'w']]]);
    usort($dated, static fn ($a, $b) => [get_post_meta($a->ID, '_qtp_when', true), get_post_meta($a->ID, '_qtp_t1', true), $a->ID]
        <=> [get_post_meta($b->ID, '_qtp_when', true), get_post_meta($b->ID, '_qtp_t1', true), $b->ID]);
    $order = array_flip(array_keys(QTP_STANDIN_WEEKDAYS));
    usort($weekly, static fn ($a, $b) => [$order[get_post_meta($a->ID, '_qtp_wtag', true)] ?? 9, get_post_meta($a->ID, '_qtp_t1', true), $a->ID]
        <=> [$order[get_post_meta($b->ID, '_qtp_wtag', true)] ?? 9, get_post_meta($b->ID, '_qtp_t1', true), $b->ID]);

    $short = ['Mon' => 'Mo.', 'Tue' => 'Di.', 'Wed' => 'Mi.', 'Thu' => 'Do.', 'Fri' => 'Fr.', 'Sat' => 'Sa.', 'Sun' => 'So.'];
    $time = static fn (string $v): string => strlen($v) === 4 ? substr($v, 0, 2) . ':' . substr($v, 2, 2) : '';
    $centre = static function (int $id): string {
        $terms = wp_get_object_terms($id, 'location_cat', ['fields' => 'names']);

        return is_array($terms) && $terms !== [] ? (string) $terms[0] : '';
    };
    $band = static function (int $id, bool $isWeekly): string {
        $w = $isWeekly ? ' weekly' : '';
        if (get_post_meta($id, '_qtp_storno', true) === 'ja') {
            return '<div class="qtp-article-cancelled' . $w . '"><div class="qtp-article-cancelled-wrap"><div class="qtp-article-cancelled-overlay"></div><span>abgesagt</span></div></div>';
        }
        if (get_post_meta($id, '_qtp_pause', true) === '1') {
            return '<div class="qtp-article-cancelled qtp-vacation' . $w . '"><div class="qtp-article-cancelled-wrap"><div class="qtp-article-cancelled-overlay"></div><span>Ferienpause</span></div></div>';
        }

        return '';
    };

    $html = '<main id="main"><form class="filter-form" data-perpage="8"></form>';
    $html .= '<section class="events-simple-programme"><h2>Veranstaltungen</h2><div class="event-listing row">';
    foreach ($dated as $p) {
        $when = (string) get_post_meta($p->ID, '_qtp_when', true);
        $d = DateTimeImmutable::createFromFormat('!Ymd', $when);
        $day = $d ? $short[$d->format('D')] . ', ' . $d->format('d.m.Y') : '';
        $t = $time((string) get_post_meta($p->ID, '_qtp_t1', true)) . ' - ' . $time((string) get_post_meta($p->ID, '_qtp_t2', true));
        $link = esc_url(get_permalink($p));
        $img = has_post_thumbnail($p) ? '<a href="' . $link . '">' . get_the_post_thumbnail($p, 'medium') . '</a>' : '';
        $html .= '<div class="col-xs-12 col-sm-6"><div class="qtp-article-preview" data-post-id="' . (int) $p->ID . '">' . $band($p->ID, false) . $img
            . '<div class="qtp-article-preview-content"><div class="qtp-article-meta"><span class="qtp-article-date">' . esc_html($day) . '</span><span class="qtp-article-date">' . esc_html($t) . '</span></div>'
            . '<a href="' . $link . '"><h3>' . esc_html(get_the_title($p)) . '</h3><p>' . esc_html($centre($p->ID)) . ' </p></a></div>'
            . '<a href="' . $link . '" class="qtp-read-more">mehr lesen</a></div></div>';
    }
    $html .= '</div></section>';
    $html .= '<section class="events-listing-weekly"><h2>Wöchentliche Angebote</h2><div class="weekly-section">';
    foreach ($weekly as $p) {
        $wtag = (string) get_post_meta($p->ID, '_qtp_wtag', true);
        $t = $time((string) get_post_meta($p->ID, '_qtp_t1', true)) . ' - ' . $time((string) get_post_meta($p->ID, '_qtp_t2', true)) . ' Uhr';
        $link = esc_url(get_permalink($p));
        $html .= '<div class="qtp-article-preview" data-post-id="' . (int) $p->ID . '">' . $band($p->ID, true)
            . '<div class="qtp-article-preview-content"><a href="' . $link . '"><h3>' . esc_html(get_the_title($p)) . '</h3><p>' . esc_html($centre($p->ID)) . ' </p></a>'
            . '<div class="qtp-article-meta"><span class="qtp-article-date">' . esc_html(QTP_STANDIN_WEEKDAYS[$wtag] ?? '') . '</span> <span class="qtp-article-date">' . esc_html($t) . '</span></div></div>'
            . '<a href="' . $link . '" class="qtp-read-more">mehr lesen</a></div>';
    }
    $html .= '</div></section></main>';

    return $html;
}

/** location_cat term IDs from the request, as the real page takes them: ?location_cat[52]=Quartierzentrum%20Union */
function qtp_standin_requested_centres(): array
{
    $raw = isset($_GET['location_cat']) && is_array($_GET['location_cat']) ? array_keys($_GET['location_cat']) : [];

    return array_values(array_filter(array_map('intval', $raw)));
}

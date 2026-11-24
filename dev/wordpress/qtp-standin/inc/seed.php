<?php
/**
 * Seed data of the stand-in. Real term names from plan section 4.5; the 15 other centres and all
 * texts are invented. Union's hand-entered records follow docs/dev/programme-page-notes.md.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

const QTP_STANDIN_TOPICS = ['Essen & Trinken', 'Kulturelles & Musik', 'Beratung & Bildung', 'Bewegung & Entspannung', 'Kreativität & Spielen', 'Offener Treffpunkt', 'Märkte & Feste'];
const QTP_STANDIN_TARGETGROUPS = ['Für Babys und Kleinkinder', 'Für Kinder', 'Für Jugendliche', 'Für Erwachsene', 'Für Senioren'];
const QTP_STANDIN_CENTRES = [
    'Quartierzentrum Union', 'Quartiertreffpunkt Rheinbogen', 'Quartiertreffpunkt Wiesenau', 'Quartiertreffpunkt Birsfeld',
    'Quartiertreffpunkt Lindenhof', 'Quartiertreffpunkt Am Bach', 'Quartiertreffpunkt Sonnenrain', 'Quartiertreffpunkt Erlenmatt-Süd',
    'Quartiertreffpunkt Kastanienplatz', 'Quartiertreffpunkt Hafenbecken', 'Quartiertreffpunkt Mühlegraben', 'Quartiertreffpunkt Rosengarten',
    'Quartiertreffpunkt Bahnhof Ost', 'Quartiertreffpunkt Hügelweg', 'Quartiertreffpunkt Brunnmatt', 'Quartiertreffpunkt Uferpark',
];

/** @return int term id */
function qtp_standin_term(string $name, string $taxonomy): int
{
    $term = get_term_by('name', $name, $taxonomy);
    if ($term instanceof WP_Term) {
        return (int) $term->term_id;
    }
    $created = wp_insert_term($name, $taxonomy);
    if (is_wp_error($created)) {
        throw new RuntimeException($created->get_error_message());
    }

    return (int) $created['term_id'];
}

/**
 * One hand-entered record, written the way the edit screen writes it.
 *
 * @param array<string, string> $form see qtp_standin_apply_form()
 * @param list<string> $topics
 * @param list<string> $groups
 */
function qtp_standin_record(string $title, string $slug, string $centre, array $form, array $topics = [], array $groups = [], string $body = ''): int
{
    $id = wp_insert_post([
        'post_type' => 'events',
        'post_status' => 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => $body !== '' ? $body : '<p>Von Hand erfasster Eintrag (Testdaten).</p>',
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $form += ['qx_h' => '<p>Kontakt: info@example.org</p>'];
    qtp_standin_apply_form((int) $id, $form);
    update_post_meta((int) $id, '_qtp_seed', '1');
    wp_set_object_terms((int) $id, [qtp_standin_term($centre, 'location_cat')], 'location_cat');
    wp_set_object_terms((int) $id, array_map(static fn ($t) => qtp_standin_term($t, 'topic'), $topics), 'topic');
    wp_set_object_terms((int) $id, array_map(static fn ($t) => qtp_standin_term($t, 'targetgroup'), $groups), 'targetgroup');

    return (int) $id;
}

/** Deletes every event (and its revisions). */
function qtp_standin_delete_all_events(): void
{
    global $wpdb;
    $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'events'));
    foreach ($ids as $id) {
        wp_delete_post((int) $id, true);
    }
}

/**
 * Terms, centres and Union's hand-entered records.
 *
 * @param bool $wipeEvents delete all events first (default for dev/wordpress/seed)
 * @return array<string, int> ids of the seeded records by slug
 */
function qtp_standin_seed(bool $wipeEvents = true): array
{
    foreach (QTP_STANDIN_TOPICS as $t) {
        qtp_standin_term($t, 'topic');
    }
    foreach (QTP_STANDIN_TARGETGROUPS as $t) {
        qtp_standin_term($t, 'targetgroup');
    }
    foreach (QTP_STANDIN_CENTRES as $c) {
        $term = qtp_standin_term($c, 'location_cat');
        if (!get_page_by_path(sanitize_title($c), OBJECT, 'locations')) {
            $loc = wp_insert_post(['post_type' => 'locations', 'post_status' => 'publish', 'post_title' => $c, 'post_name' => sanitize_title($c)]);
            wp_set_object_terms((int) $loc, [$term], 'location_cat');
        }
    }
    if (!get_page_by_path('programm-hinweis', OBJECT, 'news')) {
        wp_insert_post(['post_type' => 'news', 'post_status' => 'publish', 'post_title' => 'Programm-Hinweis', 'post_name' => 'programm-hinweis', 'post_content' => '<p>Testdaten.</p>']);
    }
    if ($wipeEvents) {
        qtp_standin_delete_all_events();
    }

    $u = 'Quartierzentrum Union';
    $ids = [];
    // Dated events shown on 23 November 2026.
    $ids['atelier-cuisine-globale'] = qtp_standin_record('Atelier Cuisine Globale', 'atelier-cuisine-globale', $u, ['qx_a' => '2026-11-29', 'qx_b' => '09:00', 'qx_c' => '14:00'], ['Essen & Trinken'], ['Für Erwachsene']);
    $ids['treffpunkt-fuer-aeltere-quartierbewohnerinnen-5'] = qtp_standin_record('Treffpunkt für ältere Quartierbewohner:innen', 'treffpunkt-fuer-aeltere-quartierbewohnerinnen-5', $u, ['qx_a' => '2026-12-16', 'qx_b' => '14:00', 'qx_c' => '16:00'], ['Offener Treffpunkt'], ['Für Senioren']);
    // Weekly offers shown on 23 November 2026 (the first 8).
    $weekly = [
        ['Deutsch im Alltag A', 'deutsch-fuer-den-alltag-2', 'Mo', '09:15', '11:00', ['Beratung & Bildung'], ['Für Erwachsene']],
        ['Café Bâlance', 'cafe-balance-8', 'Mo', '14:15', '15:15', ['Bewegung & Entspannung'], ['Für Senioren']],
        ['Kinderpiazza', 'kinderpiazza-17', 'Mo', '14:00', '17:00', ['Kreativität & Spielen'], ['Für Kinder']],
        ['Jugendschach & Schach- und Denkspiele', 'jugendschach-schach-und-denkspiele-33', 'Mo', '18:00', '00:00', ['Kreativität & Spielen'], ['Für Jugendliche']],
        ['Deutsch im Alltag B', 'deutsch-fuer-den-alltag', 'Mo', '16:00', '17:45', ['Beratung & Bildung'], ['Für Erwachsene']],
        ['Yoga am Montag (Start 09:00 Uhr)', 'yoga-am-montag-start-0900-uhr-5', 'Mo', '09:00', '10:00', ['Bewegung & Entspannung'], ['Für Erwachsene']],
        ['Kinderpiazza', 'kinderpiazza-saisonbeginn', 'Mo', '14:00', '17:00', ['Kreativität & Spielen'], ['Für Kinder']],
        ['Wir reden Deutsch', 'spielend-deutsch-lernen-6', 'Di', '09:30', '11:00', ['Beratung & Bildung'], ['Für Erwachsene']],
    ];
    foreach ($weekly as [$title, $slug, $day, $from, $to, $topics, $groups]) {
        $ids[$slug] = qtp_standin_record($title, $slug, $u, ['qx_a' => '2026-08-17', 'qx_b' => $from, 'qx_c' => $to, 'qx_d' => '1', 'qx_e' => $day], $topics, $groups, '<p>Text der Website zu «' . esc_html($title) . '» (von Hand erfasst).</p>');
    }
    // Another centre's records: a sync for Union must never touch them (R-04).
    $ids['rheinbogen-mittagstisch'] = qtp_standin_record('Mittagstisch Rheinbogen', 'rheinbogen-mittagstisch', 'Quartiertreffpunkt Rheinbogen', ['qx_a' => '2026-08-19', 'qx_b' => '12:00', 'qx_c' => '13:30', 'qx_d' => '1', 'qx_e' => 'Mi'], ['Essen & Trinken'], ['Für Erwachsene']);
    $ids['rheinbogen-flohmarkt'] = qtp_standin_record('Flohmarkt im Hof', 'rheinbogen-flohmarkt', 'Quartiertreffpunkt Rheinbogen', ['qx_a' => '2027-01-16', 'qx_b' => '10:00', 'qx_c' => '15:00'], ['Märkte & Feste'], ['Für Erwachsene']);
    flush_rewrite_rules(false);

    return $ids;
}

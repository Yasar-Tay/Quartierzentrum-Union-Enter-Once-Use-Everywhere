<?php
get_header();
if (have_posts()) {
    while (have_posts()) {
        the_post();
        echo '<article><h1>' . esc_html(get_the_title()) . '</h1>';
        if (get_post_type() === 'events') {
            $wtag = (string) get_post_meta(get_the_ID(), '_qtp_wtag', true);
            $t = static fn (string $v): string => strlen($v) === 4 ? substr($v, 0, 2) . ':' . substr($v, 2, 2) : '';
            $range = $t((string) get_post_meta(get_the_ID(), '_qtp_t1', true)) . ' - ' . $t((string) get_post_meta(get_the_ID(), '_qtp_t2', true)) . ' Uhr';
            if (get_post_meta(get_the_ID(), '_qtp_rhythm', true) === 'w') {
                echo '<p class="qtp-when">Jeden ' . esc_html(QTP_STANDIN_WEEKDAYS[$wtag] ?? '') . ', ' . esc_html($range) . '</p>';
            } else {
                $d = DateTimeImmutable::createFromFormat('!Ymd', (string) get_post_meta(get_the_ID(), '_qtp_when', true));
                echo '<p class="qtp-when">' . esc_html($d ? $d->format('d.m.Y') : '') . ', ' . esc_html($range) . '</p>';
            }
            if (get_post_meta(get_the_ID(), '_qtp_storno', true) === 'ja') {
                echo '<p class="qtp-band">abgesagt</p>';
            }
            if (get_post_meta(get_the_ID(), '_qtp_pause', true) === '1') {
                echo '<p class="qtp-band">Ferienpause</p>';
            }
            echo '<aside><h2>Organisation und Information</h2>' . wp_kses_post((string) get_post_meta(get_the_ID(), '_qtp_orginfo', true)) . '</aside>';
        }
        the_content();
        echo '</article>';
    }
}
get_footer();

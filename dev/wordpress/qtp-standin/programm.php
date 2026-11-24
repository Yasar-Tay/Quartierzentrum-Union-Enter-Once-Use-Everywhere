<?php
/** Programme page of the stand-in: /programm/?location_cat[<id>]=<name> */
get_header();
echo qtp_standin_programme_html(qtp_standin_requested_centres()); // phpcs:ignore -- escaped inside
get_footer();

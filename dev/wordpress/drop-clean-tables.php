<?php
// Drops the tables of the temporary clean site (prefix clean_) and nothing else.
$m = new mysqli((string) getenv('WORDPRESS_DB_HOST'), (string) getenv('WORDPRESS_DB_USER'), (string) getenv('WORDPRESS_DB_PASSWORD'), (string) getenv('WORDPRESS_DB_NAME'));
$r = $m->query("SHOW TABLES LIKE 'clean\\_%'");
foreach ($r->fetch_all() as [$table]) {
    if (str_starts_with($table, 'clean_')) {
        $m->query('DROP TABLE `' . $table . '`');
    }
}

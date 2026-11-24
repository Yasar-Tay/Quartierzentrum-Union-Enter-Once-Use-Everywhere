#!/bin/sh
# Runs inside the WP-CLI container (see test-zip-install).
set -e
P=/var/www/html/wp-content/plugins/enter-once-sync
C=/tmp/clean-wp
rm -rf "$C"
mkdir -p "$C/wp-content/plugins" "$C/wp-content/themes"
cd /var/www/html
for f in *; do
  [ "$f" = wp-content ] || [ "$f" = wp-config.php ] || cp -r "$f" "$C/"
done
for t in wp-content/themes/twenty*; do [ -d "$t" ] && cp -r "$t" "$C/wp-content/themes/"; done
cd "$C"
php "$P/dev/wordpress/drop-clean-tables.php"
wp config create --dbname="$WORDPRESS_DB_NAME" --dbuser="$WORDPRESS_DB_USER" --dbpass="$WORDPRESS_DB_PASSWORD" --dbhost="$WORDPRESS_DB_HOST" --dbprefix=clean_ --skip-check --quiet
wp core install --url=http://localhost/clean --title=Clean --admin_user=clean --admin_password=clean-dev-only --admin_email=clean@example.org --skip-email --quiet
wp plugin install "$P/dist/enter-once-sync.zip" --activate
wp plugin list --name=enter-once-sync --fields=name,status,version
wp eval-file "$P/dev/wordpress/clean-install-check.php"
wp enteronce status
if wp enteronce sync --now="2026-11-19 00:00" >/tmp/eo-now.txt 2>&1; then echo "FAIL: --now accepted on a production site"; exit 1; fi
grep -q ENTERONCE_DEV /tmp/eo-now.txt && echo "--now refused outside development: OK"
wp plugin deactivate enter-once-sync --quiet
php "$P/dev/wordpress/drop-clean-tables.php"
rm -rf "$C"
echo "Clean install OK"

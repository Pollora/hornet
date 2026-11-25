#!/bin/sh
# Runs tests/E2E/hardening.sh against a throwaway WordPress in Docker (mysql:8.0 + wordpress:cli-php8.4).
# Usage: WP_SCRATCH=/tmp/wp-e2e-hornet tests/E2E/run-docker.sh [package dir]
set -e
S=${WP_SCRATCH:-/tmp/wp-e2e-hornet}
PKG=${1:-$(cd "$(dirname "$0")/../.." && pwd)}
docker rm -f hornet-e2e-mysql >/dev/null 2>&1 || true
docker network create hornet-e2e-net >/dev/null 2>&1 || true
docker run -d --name hornet-e2e-mysql --network hornet-e2e-net -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=wordpress mysql:8.0 >/dev/null
for i in $(seq 1 60); do docker exec hornet-e2e-mysql mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1 && break; sleep 2; done
mkdir -p "$S/wp"
status=0
docker run --rm --network hornet-e2e-net -u 0 -v "$S/wp":/var/www/html -v "$PKG":/pkg wordpress:cli-php8.4 sh -c '
  wp() { php -d memory_limit=1G /usr/local/bin/wp --allow-root "$@"; }
  cd /var/www/html
  # The zip, not `wp core download`: its tar extraction cuts the long file names of wp-includes/php-ai-client, and the scanner rightly reports them
  [ -f wp-load.php ] || { curl -fsSL -o /tmp/wp.zip https://wordpress.org/latest.zip && unzip -q /tmp/wp.zip -d /tmp && cp -R /tmp/wordpress/. /var/www/html/; }
  [ -f wp-config.php ] || wp config create --dbhost=hornet-e2e-mysql --dbname=wordpress --dbuser=root --skip-check --quiet
  wp core is-installed 2>/dev/null || wp core install --url=http://127.0.0.1:8082 --title=t --admin_user=admin --admin_password=pass --admin_email=a@a.test --skip-email --quiet
  wp plugin is-installed two-factor 2>/dev/null || wp plugin install two-factor --activate --quiet
  wp plugin activate two-factor >/dev/null 2>&1 || true
  sh /pkg/tests/E2E/hardening.sh' || status=$?
docker rm -f hornet-e2e-mysql >/dev/null 2>&1 || true
exit $status

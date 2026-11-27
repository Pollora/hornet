#!/bin/sh
# Runs tests/E2E/multisite.sh against a throwaway WordPress network in Docker (mysql:8.0 + wordpress:php8.4-apache).
#
# Usage: tests/E2E/run-multisite.sh [subdirectory|subdomain]
#   HMS_PREFIX   prefix of the containers and of the Docker network (hms)
#   HMS_SCRATCH  folder of the throwaway install (/tmp/$HMS_PREFIX-e2e)
#   HMS_PORT     port of the network, the same inside and outside the container (8611)
#   HMS_ZIP      a WordPress zip to unpack instead of downloading the latest one
#   HMS_KEEP=1   leave the network running afterwards (to look at it in a browser: http://127.0.0.1:$HMS_PORT, admin / pass)
set -e

MODE=${1:-subdirectory}
PREFIX=${HMS_PREFIX:-hms}
PORT=${HMS_PORT:-8611}
S=${HMS_SCRATCH:-/tmp/$PREFIX-e2e}/$MODE
PKG=$(cd "$(dirname "$0")/../.." && pwd)
NET=$PREFIX-net
DB=$PREFIX-db
WEB=$PREFIX-web

case $MODE in
  subdirectory) DOMAIN="127.0.0.1:$PORT"; SUB="" ;;
  subdomain) DOMAIN="hms.localhost:$PORT"; SUB=--subdomains ;;
  *) echo "Usage: $0 [subdirectory|subdomain]" >&2; exit 2 ;;
esac

clean() {
  docker rm -f "$WEB" "$DB" >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}
clean
rm -rf "$S"
mkdir -p "$S/wp" "$S/bin"

if [ -n "${HMS_ZIP:-}" ]; then cp "$HMS_ZIP" "$S/wp.zip"; else curl -fsSL -o "$S/wp.zip" https://wordpress.org/latest.zip; fi
# The zip, not `wp core download`: its tar extraction cuts the long file names of wp-includes/php-ai-client
unzip -q "$S/wp.zip" -d "$S/unzipped" && cp -R "$S/unzipped/wordpress/." "$S/wp/" && rm -rf "$S/unzipped" "$S/wp.zip"
# The web image has no WP-CLI: it is the one of the CLI image
docker run --rm --entrypoint cat wordpress:cli-php8.4 /usr/local/bin/wp > "$S/bin/wp"
printf 'opcache.enable=0\nmemory_limit=512M\nlog_errors=On\nerror_reporting=E_ALL\nerror_log=/tmp/hms-debug.log\n' > "$S/bin/hms.ini"

docker network create "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE=wordpress mysql:8.0 >/dev/null
docker run -d --name "$WEB" --network "$NET" -p "127.0.0.1:$PORT:$PORT" \
  --add-host hms.localhost:127.0.0.1 --add-host two.hms.localhost:127.0.0.1 --add-host three.hms.localhost:127.0.0.1 \
  -v "$S/wp":/var/www/html -v "$PKG":/pkg:ro -v "$S/bin/wp":/usr/local/bin/wp:ro -v "$S/bin/hms.ini":/usr/local/etc/php/conf.d/zz-hms.ini:ro \
  wordpress:php8.4-apache sh -c "sed -i 's/^Listen 80\$/Listen $PORT/' /etc/apache2/ports.conf && sed -i 's/:80>/:$PORT>/' /etc/apache2/sites-available/000-default.conf && a2enmod rewrite >/dev/null && exec apache2-foreground" >/dev/null

# The server on the network, not the temporary one of the first start, which listens on a socket only
for i in $(seq 1 90); do docker exec "$DB" mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1 && break; sleep 2; done
docker exec "$WEB" sh -c 'chown -R www-data:www-data /var/www/html'

status=0
docker exec -u www-data -e HMS_MODE="$MODE" -e HMS_DOMAIN="$DOMAIN" -e HMS_DB="$DB" -e HMS_SUBDOMAINS="$SUB" "$WEB" sh /pkg/tests/E2E/multisite.sh || status=$?

if [ "${HMS_KEEP:-0}" = 1 ]; then
  echo "Left running: http://$DOMAIN (containers $WEB and $DB, files in $S)"
else
  clean
fi
exit $status

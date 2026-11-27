#!/bin/sh
# Real HTTP and WP-CLI against a real WordPress network: what Hornet does on each site of it.
#
# It runs inside the web container that tests/E2E/run-multisite.sh sets up (Apache, PHP, WP-CLI at /usr/local/bin/wp, the
# package at /pkg). Exit code 1 when an assertion fails.
set -u

WP_DIR=${WP_DIR:-/var/www/html}
export HOME=/tmp
failed=0

ok() { echo "  ok    $1"; }
ko() { echo "  FAIL  $1"; failed=1; }
check() { desc=$1; shift; if "$@"; then ok "$desc"; else ko "$desc"; fi }

wp() { php -d memory_limit=1G /usr/local/bin/wp --path="$WP_DIR" "$@"; }
site_url_of() {
  case "$HMS_MODE:$1" in
    *:1) echo "http://$HMS_DOMAIN" ;;
    subdirectory:2) echo "http://$HMS_DOMAIN/two" ;;
    subdirectory:3) echo "http://$HMS_DOMAIN/three" ;;
    subdomain:2) echo "http://two.$HMS_DOMAIN" ;;
    subdomain:3) echo "http://three.$HMS_DOMAIN" ;;
  esac
}
# shellcheck source=tests/E2E/multisite-install.sh
. /pkg/tests/E2E/multisite-install.sh

ONE=$(site_url_of 1)
TWO=$(site_url_of 2)
THREE=$(site_url_of 3)

configure() { wp config set HORNET_CONFIG "$1" --raw >/dev/null; }
status() { curl -s -o /dev/null -w '%{http_code}' --max-time 30 "$@"; }
headers() { curl -s -o /dev/null -D - --max-time 30 "$@"; }
body() { curl -s --max-time 30 "$@"; }
status_is() { want=$1; shift; got=$(status "$@"); [ "$got" = "$want" ] || { echo "        got $got, wanted $want: $*"; return 1; }; }
body_has() { needle=$1; shift; body "$@" | grep -qF -- "$needle"; }
body_lacks() { needle=$1; shift; ! body "$@" | grep -qF -- "$needle"; }
has() { printf '%s' "$1" | grep -qF -- "$2"; }
lacks() { ! printf '%s' "$1" | grep -qF -- "$2"; }
count_is() { [ "$(printf '%s\n' "$1" | grep -cF -- "$2")" = "$3" ]; }
tables() { wp eval 'global $wpdb; echo implode(" ", $wpdb->get_col("SHOW TABLES LIKE \"%hornet_journal\"")), " ";'; }
audit() { wp hornet status --all --fail-on=none --url="$1" 2>/dev/null; }
journal() { wp hornet journal list --format=csv --limit=200 --url="$1" 2>/dev/null; }

# login <user> <site url>: a cookie jar in /tmp/hms-jar-<user>, and $NONCE for the REST API of that site
login() {
  jar=/tmp/hms-jar-$1
  rm -f "$jar"
  curl -s -o /dev/null -c "$jar" -b 'wordpress_test_cookie=WP%20Cookie%20check' --max-time 30 --data "log=$1&pwd=pass&testcookie=1" "$ONE/wp-login.php"
  NONCE=$(curl -s -b "$jar" "${2:-$ONE}/wp-admin/admin-ajax.php?action=rest-nonce")
}

# what an earlier run left behind
wp config delete HORNET_CONFIG >/dev/null 2>&1
rm -f /tmp/hms-mail.log

NONCE=
configure "array('journal' => array('enabled' => true), 'abilities' => array('enabled' => true, 'writes' => true), 'notifications' => array('schedule' => 'off', 'channels' => array('ops' => array('type' => 'mail', 'to' => array('ops@a.test')))))"

echo "1. who may see and change what ($HMS_MODE network)"
ROUTES="GET:status GET:scan POST:scan GET:journal POST:notify/test POST:findings/xml-rpc/ignore DELETE:findings/xml-rpc/ignore"
# refused <user or anon> <wanted status>: every route, written both ways
refused() {
  who=$1; want=$2
  for route in $ROUTES; do
    method=${route%%:*}; path=${route#*:}
    for url in "$ONE/wp-json/hornet/v1/$path" "$ONE/?rest_route=/hornet/v1/$path"; do
      if [ "$who" = anon ]; then
        status_is "$want" -X "$method" "$url" -H 'Content-Type: application/json' --data '{"channel":"ops","reason":"not allowed here"}' || return 1
      else
        status_is "$want" -X "$method" "$url" -b "/tmp/hms-jar-$who" -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' --data '{"channel":"ops","reason":"not allowed here"}' || return 1
      fi
    done
  done
  for site in "$TWO" "$THREE"; do
    status_is 404 -b "/tmp/hms-jar-$who" -H "X-WP-Nonce: $NONCE" "$site/wp-json/hornet/v1/status" || return 1
  done
}
# abilities_refused <user>: the abilities that read, and one that writes
abilities_refused() {
  for ability in get-status list-findings read-journal; do
    status_is 403 -b "/tmp/hms-jar-$1" -H "X-WP-Nonce: $NONCE" "$ONE/wp-json/wp-abilities/v1/abilities/hornet/$ability/run" || return 1
  done
  status_is 403 -b "/tmp/hms-jar-$1" -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' --data '{"input":{"check":"xml-rpc","reason":"not allowed here"}}' "$ONE/wp-json/wp-abilities/v1/abilities/hornet/set-aside-finding/run"
}
# pages_refused <user> <site>: the screen, its files, and Site Health (which shows what the scan found for the network)
pages_refused() {
  for page in wp-admin/tools.php?page=hornet-security wp-admin/network/settings.php?page=hornet-security wp-admin/site-health.php 'wp-admin/site-health.php?tab=debug'; do
    status_is 403 -b "/tmp/hms-jar-$1" "$2/$page" || return 1
  done
  body_lacks 'hornet-admin' -b "/tmp/hms-jar-$1" "$2/wp-admin/admin.php?hornet-asset=index.js"
}
check "a visitor is refused every route" refused anon 401
for user in mainadmin siteadmin; do
  login "$user" "$ONE"
  # a user who is not a member of the main site asks for the nonce on their own
  [ "$user" = siteadmin ] && NONCE=$(curl -s -b "/tmp/hms-jar-$user" "$TWO/wp-admin/admin-ajax.php?action=rest-nonce")
  check "$user (not a super administrator) is refused every route" refused "$user" 403
  check "$user is refused the abilities" abilities_refused "$user"
  check "$user is refused the screen, its files and Site Health on the main site" pages_refused "$user" "$ONE"
done
curl -s -o /dev/null -c /tmp/hms-jar-siteadmin -b 'wordpress_test_cookie=WP%20Cookie%20check' --data "log=siteadmin&pwd=pass&testcookie=1" "$TWO/wp-login.php"
check "the administrator of a site is refused Site Health and the screen on their own site" pages_refused siteadmin "$TWO"
login rootonly "$ONE"
check "a super administrator has the routes, with no role on any site" status_is 200 -b /tmp/hms-jar-rootonly -H "X-WP-Nonce: $NONCE" "$ONE/wp-json/hornet/v1/status"
check "and the screen, in the network administration" body_has 'hornet-admin' -b /tmp/hms-jar-rootonly "$ONE/wp-admin/network/settings.php?page=hornet-security"
check "whose script is served" body_has 'hornet/v1' -b /tmp/hms-jar-rootonly "$ONE/wp-admin/network/admin.php?hornet-asset=index.js"
check "the index of the REST API of no site names Hornet to a visitor" sh -c "! { curl -s '$ONE/wp-json/'; curl -s '$TWO/wp-json/'; } | grep -q hornet"

echo "2. the audit of each site"
for site in "$ONE" "$TWO"; do
  check "a super administrator can edit files: the audit of $site says so" test "$(audit "$site" | grep -cE '^open.*file-editor')" = 1
done
configure "array('hardening' => array('file_editor' => true))"
check "until it is hardened" has "$(audit "$THREE" | grep file-editor)" 'pass'

echo "3. the settings of one site"
configure "array('hardening' => array('user_enumeration' => true, 'hide_version' => true), 'headers' => array('content_type_options' => true, 'referrer_policy' => 'same-origin'), 'sites' => array(3 => array('headers' => array('permissions_policy' => 'camera=()'))))"
for site in "$ONE" "$TWO"; do
  H=$(headers "$site/")
  check "the shared headers on $site" has "$H" 'X-Content-Type-Options: nosniff'
  check "?author=1 is a 404 on $site" status_is 404 "$site/?author=1"
  check "no user list on $site" status_is 404 "$site/wp-json/wp/v2/users"
  check "the dashboard and login screen have them too on $site" has "$(headers "$site/wp-login.php")" 'Referrer-Policy: same-origin'
done
H=$(headers "$THREE/")
check "a site's own headers replace the shared ones" has "$H" 'Permissions-Policy: camera=()'
check "all of them: a top-level key is replaced whole" lacks "$H" 'X-Content-Type-Options'
check "the keys the site does not give stay shared" status_is 404 "$THREE/?author=1"
check "signing up has the headers" has "$(headers "$ONE/wp-signup.php")" 'X-Content-Type-Options: nosniff'
configure "array('hardening' => array('restrict_rest_api' => true), 'sites' => array(3 => array('hardening' => array())))"
check "the REST API closed to visitors on a site" status_is 401 "$TWO/wp-json/wp/v2/posts"
check "and open on the site that says so" status_is 200 "$THREE/wp-json/wp/v2/posts"

echo "4. the scan belongs to the main site"
configure "array('scanner' => array('schedule' => 'daily'), 'journal' => array('enabled' => true))"
for site in "$ONE" "$TWO" "$THREE"; do body "$site/" >/dev/null; done
scheduled() { wp cron event list --field=hook --url="$1" 2>/dev/null | grep -c '^pollora/hornet/scheduled_scan$'; }
check "it is scheduled once, on the main site" test "$(scheduled "$ONE") $(scheduled "$TWO") $(scheduled "$THREE")" = '1 0 0'
mkdir -p wp-content/uploads/hms && echo '<?php echo 1;' > wp-content/uploads/hms/planted.php
wp post create --post_title=hms --post_status=publish --post_content='<script>eval(atob("YWxlcnQoMSk="))</script>' --porcelain >/dev/null
wp hornet scan --fail-on=none >/dev/null 2>&1
check "it finds the file in the uploads and the script in the main database" has "$(audit "$ONE" | grep -E 'suspicious-files|database-scripts' | tr '\n' ' ')" 'open'
refusal=$(wp hornet scan --url="$TWO" 2>&1)
check "a scan from another site is refused, and says where to run it" has "$refusal" "--url=$ONE"
check "and leaves what the network knows" test "$(audit "$ONE" | grep -cE '^open.*(suspicious-files|database-scripts)')" = 2
check "every site shows what was found in the shared files" has "$(audit "$THREE" | grep suspicious-files)" 'open'
stored=$(wp eval 'echo get_network_option(null, "hornet_scan") ? "network " : "", get_network_option(null, "hornet_baseline_themes") ? "baselines" : "";')
check "the result and the first looks are kept for the network" test "$stored" = 'network baselines'
rm -rf wp-content/uploads/hms

echo "5. a finding set aside"
configure "array('journal' => array('enabled' => true))"
login admin "$ONE"
check "the super administrator sets one aside" status_is 200 -b /tmp/hms-jar-admin -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' --data '{"reason":"on purpose, for this test"}' "$ONE/wp-json/hornet/v1/findings/xml-rpc/ignore"
check "on the main site" has "$(audit "$ONE" | grep xml-rpc)" 'ignored'
check "and on that site only" has "$(audit "$TWO" | grep xml-rpc)" 'open'
curl -s -o /dev/null -X DELETE -b /tmp/hms-jar-admin -H "X-WP-Nonce: $NONCE" "$ONE/wp-json/hornet/v1/findings/xml-rpc/ignore"

echo "6. the journal"
for site in "$ONE" "$TWO" "$THREE"; do wp hornet journal drop --yes --url="$site" >/dev/null 2>&1; body "$site/" >/dev/null; done
configure "array('journal' => array('enabled' => true))"
wp user create joiner joiner@a.test --role=subscriber --user_pass=pass --quiet
JOINER=$(wp user get joiner --field=ID)
wp eval "add_user_to_blog(2, $JOINER, 'administrator');" --user=admin
wp eval "switch_to_blog(3); add_user_to_blog(3, $JOINER, 'editor'); (new WP_User($JOINER))->add_role('author'); restore_current_blog();" --user=admin
wp eval "remove_user_from_blog($JOINER, 2);" --user=admin
check "an account added to a site is in that site's journal" has "$(journal "$TWO")" '""added"":""administrator""'
check "a role given from the network administration too" has "$(journal "$THREE")" '""added"":""author""'
check "and not in the main one" lacks "$(journal "$ONE")" '""added"":""author""'
check "its removal is written there" has "$(journal "$TWO")" '""removed"":""administrator""'
check "and not as a deletion" lacks "$(journal "$TWO")" 'user.deleted'
wp eval "require_once ABSPATH.'wp-admin/includes/ms.php'; wpmu_delete_user($JOINER);" --user=admin
check "an account deleted from the network is in the main journal" has "$(journal "$ONE")" "user.deleted,cli,admin,user:$JOINER"
wp site create --slug=four --quiet >/dev/null 2>&1
FOUR=$(wp site list --field=url | grep four)
check "a new site's journal has who was given it" has "$(journal "$FOUR")" '""added"":""administrator""'

echo "7. retention and privacy, site by site"
wp eval 'global $wpdb; $wpdb->insert($wpdb->prefix."hornet_journal", ["occurred_at" => "2020-01-01 00:00:00", "type" => "login.success", "source" => "web", "user_id" => 1, "actor" => "admin", "subject" => "user:1", "details" => "[]"]);' --url="$TWO"
wp eval 'global $wpdb; $wpdb->insert($wpdb->prefix."hornet_journal", ["occurred_at" => "2020-01-01 00:00:00", "type" => "login.success", "source" => "web", "user_id" => 1, "actor" => "admin", "subject" => "user:1", "details" => "[]"]);' --url="$THREE"
wp cron event run pollora/hornet/purge_journal --url="$TWO" >/dev/null 2>&1 || wp hornet journal purge --url="$TWO" >/dev/null
check "the purge of a site forgets what is old there" lacks "$(journal "$TWO")" '2020-01-01'
check "and leaves the other sites to theirs" has "$(journal "$THREE")" '2020-01-01'
privacy() { wp eval "\$all = apply_filters('wp_privacy_personal_data_$1', []); echo wp_json_encode(\$all['hornet-journal']['callback']('admin@a.test', 1));" --url="$2"; }
check "the export gives the events of the site it is run from" has "$(privacy exporters "$THREE")" '2020-01-01'
privacy erasers "$THREE" >/dev/null
check "the eraser takes the person out of them" lacks "$(journal "$THREE" | grep 2020-01-01)" 'admin'

echo "8. the login throttle covers the network"
configure "array('login' => array('throttle' => true, 'max_attempts' => 3), 'network' => array('trusted_proxies' => array('127.0.0.1')))"
VISITOR='X-Forwarded-For: 203.0.113.7'
attempt() { body -H "$VISITOR" --data "log=admin&pwd=$2" "$1/wp-login.php"; }
wp hornet unlock --all >/dev/null
attempt "$ONE" wrong1 >/dev/null; attempt "$TWO" wrong2 >/dev/null; attempt "$THREE" wrong3 >/dev/null
check "failures on any site count together" body_has 'Too many failed login attempts' -H "$VISITOR" --data 'log=admin&pwd=wrong4' "$ONE/wp-login.php"
check "and the right password is refused on every site" body_has 'Too many failed login attempts' -H "$VISITOR" --data 'log=admin&pwd=pass' "$TWO/wp-login.php"
check "wp hornet unlock, run once, lets the address in" has "$(wp hornet unlock 203.0.113.7)" 'Success'
check "on every site" status_is 302 -H "$VISITOR" --data 'log=admin&pwd=pass' "$TWO/wp-login.php"

echo "9. two-factor and super administrators"
configure "array('two_factor' => array('require_roles' => array('administrator')))"
login rootonly "$ONE"
for page in "$ONE/wp-admin/network/index.php" "$THREE/wp-admin/index.php"; do
  check "a super administrator without a role is sent to set it up from $page" has "$(headers -b /tmp/hms-jar-rootonly "$page")" 'profile.php?hornet_2fa=required'
done
check "and reaches the profile" status_is 200 -b /tmp/hms-jar-rootonly "$ONE/wp-admin/profile.php?hornet_2fa=required"
# The third site has one administrator by role (admin): the other is the super administrator who has none
check "the audit counts them among the administrators" has "$(audit "$THREE" | grep two-factor-coverage)" '2 administrators'

echo "10. notifications"
rm -f /tmp/hms-mail.log
configure "array('notifications' => array('schedule' => 'daily', 'channels' => array('ops' => array('type' => 'mail', 'to' => array('ops@a.test')))), 'sites' => array(3 => array('notifications' => array())))"
for site in "$ONE" "$TWO" "$THREE"; do wp hornet notify run --url="$site" >/dev/null 2>&1; done
check "each site that has channels tells its own audit" test "$(cut -d' ' -f1 /tmp/hms-mail.log | sort | tr '\n' ' ')" = '1 2 '
wp hornet notify run >/dev/null 2>&1
check "and does not repeat itself" count_is "$(cat /tmp/hms-mail.log)" 'Hornet' 2

echo "11. deleting a site"
configure "array('sites' => array(2 => array('journal' => array('enabled' => true))))"
body "$TWO/" >/dev/null
check "the site has its table" has "$(tables)" 'wp_2_hornet_journal '
wp site delete 2 --yes >/dev/null 2>&1
check "which goes with it, deleted from a site that keeps no journal" lacks "$(tables)" 'wp_2_hornet_journal '

check "no PHP error of Hornet in the server log" sh -c "! grep -iE 'PHP (Fatal|Warning|Notice|Deprecated).*(pkg/src|hornet)' /tmp/hms-debug.log 2>/dev/null"

echo
[ "$failed" = 0 ] && echo "All assertions passed." || echo "Some assertions failed."
exit "$failed"

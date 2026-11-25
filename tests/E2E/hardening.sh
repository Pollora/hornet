#!/bin/sh
# Real HTTP against a real WordPress: what Hornet's hardening and headers actually send.
#
# It runs inside a container that has WordPress installed in $WP_DIR (admin / pass), WP-CLI, curl and PHP, with the package
# at $PKG (vendor/ installed). tests/E2E/run-docker.sh sets that up. Exit code 1 when an assertion fails.
set -u

WP_DIR=${WP_DIR:-/var/www/html}
PKG=${PKG:-/pkg}
PORT=${PORT:-8082}
SECURE_PORT=${SECURE_PORT:-8083}
BASE="http://127.0.0.1:$PORT"
SECURE="http://127.0.0.1:$SECURE_PORT"
JAR=/tmp/hornet-e2e.jar
failed=0

ok() { echo "  ok    $1"; }
ko() { echo "  FAIL  $1"; failed=1; }
check() { desc=$1; shift; if "$@"; then ok "$desc"; else ko "$desc"; fi }

headers() { curl -s -o /dev/null -D - --max-time 20 "$@"; }
status() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$@"; }
body() { curl -s --max-time 20 "$@"; }

# header_present <name> <curl args...>
header_present() { name=$1; shift; headers "$@" | grep -qi "^$name:"; }
# header_is <name> <value> <curl args...>
header_is() { name=$1; value=$2; shift 2; headers "$@" | grep -i "^$name:" | grep -qF "$value"; }
status_is() { want=$1; shift; [ "$(status "$@")" = "$want" ]; }
body_has() { needle=$1; shift; body "$@" | grep -qF "$needle"; }
body_lacks() { needle=$1; shift; ! body "$@" | grep -qF "$needle"; }

wp() { php -d memory_limit=1G /usr/local/bin/wp --allow-root --path="$WP_DIR" "$@"; }
configure() { wp config set HORNET_CONFIG "$1" --raw >/dev/null; }
reset() { wp config delete HORNET_CONFIG >/dev/null 2>&1; wp config delete HORNET_DISABLED >/dev/null 2>&1; true; }

PINGBACK='<?xml version="1.0"?><methodCall><methodName>pingback.ping</methodName><params><param><value><string>http://a.test/x</string></value></param><param><value><string>http://127.0.0.1/?p=1</string></value></param></params></methodCall>'
pingback() { curl -s --max-time 20 -X POST -H 'Content-Type: text/xml' --data "$PINGBACK" "$BASE/xmlrpc.php"; }

login() {
  rm -f "$JAR"
  curl -s -o /dev/null -c "$JAR" -b 'wordpress_test_cookie=WP%20Cookie%20check' --max-time 20 \
    --data 'log=admin&pwd=pass&wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php"
}

# opcache is off for the servers: wp-config.php changes between scenarios and must be read again at once

# --- setup -------------------------------------------------------------------------------------------------
mkdir -p "$WP_DIR/wp-content/mu-plugins"
printf '<?php\nrequire "%s/vendor/autoload.php";\nrequire "%s/stubs/pollora-hornet.php";\n' "$PKG" "$PKG" > "$WP_DIR/wp-content/mu-plugins/hornet.php"
wp option update home "$BASE" >/dev/null
wp option update siteurl "$BASE" >/dev/null
wp rewrite structure '/%postname%/' >/dev/null
reset

(cd "$WP_DIR" && php -d opcache.enable=0 -S "127.0.0.1:$PORT" -t "$WP_DIR" "$PKG/tests/E2E/router.php" >/tmp/e2e-server.log 2>&1 &)
(cd "$WP_DIR" && E2E_HTTPS=1 php -d opcache.enable=0 -S "127.0.0.1:$SECURE_PORT" -t "$WP_DIR" "$PKG/tests/E2E/router.php" >/tmp/e2e-secure.log 2>&1 &)
# A server with the time limit of a web request, for the real WP-Cron (wp-cron.php) in section 8c
CRON_PORT=${CRON_PORT:-8084}
(cd "$WP_DIR" && php -d opcache.enable=0 -d max_execution_time=20 -S "127.0.0.1:$CRON_PORT" -t "$WP_DIR" "$PKG/tests/E2E/router.php" >/tmp/e2e-cron.log 2>&1 &)
for i in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null "$BASE/" && curl -s -o /dev/null "http://127.0.0.1:$CRON_PORT/" && break; sleep 1; done

# --- 1. nothing configured: nothing changes ----------------------------------------------------------------
echo "1. Hornet installed, nothing turned on: the site is as WordPress made it"
check "the front sends no nosniff"                 sh -c "! curl -s -o /dev/null -D - '$BASE/' | grep -qi '^x-content-type-options:'"
check "?author=1 still redirects to the author page" status_is 301 "$BASE/?author=1"
check "the REST API still lists the users"          body_has '"slug":"admin"' "$BASE/wp-json/wp/v2/users"
check "the pingback header is still there on a post"  header_present X-Pingback "$BASE/hello-world/"
check "the generator tag is still there"            body_has 'name="generator"' "$BASE/"
check "the REST index does not announce Hornet"                  body_lacks 'hornet/v1' "$BASE/wp-json/"
check "XML-RPC still answers a pingback with a pingback error" sh -c "! curl -s -X POST -H 'Content-Type: text/xml' --data '$PINGBACK' '$BASE/xmlrpc.php' | grep -q -- '-32601'"

# --- 2. hardening ------------------------------------------------------------------------------------------
echo "2. hardening on"
configure "array('hardening' => array('xmlrpc' => true, 'user_enumeration' => true, 'hide_version' => true, 'file_editor' => true))"
check "?author=1 is a 404 and does not show the login"     status_is 404 "$BASE/?author=1"
check "the 404 does not reveal the author"                   body_lacks 'author/admin' "$BASE/?author=1"
login
check "?author=1 still works for a logged-in user"           status_is 301 -b "$JAR" "$BASE/?author=1"
check "the REST API no longer lists the users"               body_has 'rest_no_route' "$BASE/wp-json/wp/v2/users"
check "the REST API still serves posts"                      status_is 200 "$BASE/wp-json/wp/v2/posts"
check "no user in the users sitemap"                         body_lacks 'wp-sitemap-users' "$BASE/wp-sitemap.xml"
check "the pingback header is gone from a post"             sh -c "! curl -s -o /dev/null -D - '$BASE/hello-world/' | grep -qi '^x-pingback:'"
check "the generator tag is gone"                            body_lacks 'name="generator"' "$BASE/"
check "the RSD link is gone"                                 body_lacks 'rsd+xml' "$BASE/"
check "XML-RPC: pingback.ping no longer exists"              sh -c "curl -s -X POST -H 'Content-Type: text/xml' --data '$PINGBACK' '$BASE/xmlrpc.php' | grep -q -- '-32601'"
check "the file editor is refused to an administrator"       sh -c "[ \"\$(php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' eval 'wp_set_current_user(1); echo (current_user_can(\"edit_plugins\") || current_user_can(\"edit_themes\")) ? \"yes\" : \"no\";')\" = no ]"
check "a logged-in administrator still lists users over REST" sh -c "N=\$(curl -s -b '$JAR' '$BASE/wp-admin/admin-ajax.php?action=rest-nonce'); curl -s -b '$JAR' -H \"X-WP-Nonce: \$N\" '$BASE/wp-json/wp/v2/users' | grep -q '\"slug\"'"
check "the audit sees it: XML-RPC closed, users hidden, editor off" sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status --all --fail-on=none | grep -E '^pass.*(xml-rpc|user-enumeration|file-editor)' | wc -l | grep -q '^3\$'"

# --- 3. headers --------------------------------------------------------------------------------------------
echo "3. headers on"
configure "array('headers' => array('content_type_options' => true, 'referrer_policy' => 'same-origin', 'permissions_policy' => 'camera=()', 'hsts' => array('max_age' => 31536000), 'csp' => array('policy' => \"default-src 'self'\", 'report_uri' => 'https://r.example.com/csp')))"
check "the public site gets nosniff"                         header_is X-Content-Type-Options nosniff "$BASE/"
check "the public site gets the referrer policy"             header_is Referrer-Policy same-origin "$BASE/"
check "the public site gets the permissions policy"          header_is Permissions-Policy 'camera=()' "$BASE/"
check "the public site gets the policy in report-only mode"  header_is Content-Security-Policy-Report-Only "default-src 'self'; report-uri https://r.example.com/csp" "$BASE/"
check "the policy is not enforced"                           sh -c "! curl -s -o /dev/null -D - '$BASE/' | grep -qi '^content-security-policy:'"
check "no HSTS over plain http"                              sh -c "! curl -s -o /dev/null -D - '$BASE/' | grep -qi '^strict-transport-security:'"
check "HSTS over https"                                      header_is Strict-Transport-Security 'max-age=31536000' "$SECURE/wp-login.php"
check "the login screen gets the referrer policy"            header_is Referrer-Policy same-origin "$BASE/wp-login.php"
check "the login screen does not get the policy"             sh -c "! curl -s -o /dev/null -D - '$BASE/wp-login.php' | grep -qi '^content-security-policy.*default-src'"
login
check "the dashboard gets the referrer policy, not WordPress's"  sh -c "curl -s -o /dev/null -D - -b '$JAR' '$BASE/wp-admin/' | grep -qi '^referrer-policy: same-origin' && ! curl -s -o /dev/null -D - -b '$JAR' '$BASE/wp-admin/' | grep -qi '^referrer-policy: strict-origin'"
check "the dashboard does not get the policy"                sh -c "! curl -s -o /dev/null -D - -b '$JAR' '$BASE/wp-admin/' | grep -qi '^content-security-policy.*default-src'"
check "the dashboard loads"                                  status_is 200 -b "$JAR" "$BASE/wp-admin/"

configure "array('headers' => array('csp' => array('mode' => 'enforce', 'policy' => \"default-src 'self'\", 'admin' => true)))"
check "an enforced policy uses the enforcing header"         header_is Content-Security-Policy "default-src 'self'" "$BASE/"
check "the dashboard gets it when asked"                     sh -c "curl -s -o /dev/null -D - -b '$JAR' '$BASE/wp-admin/' | grep -qi '^content-security-policy: default-src'"
check "WordPress's own frame-ancestors policy is still sent next to it" sh -c "curl -s -o /dev/null -D - -b '$JAR' '$BASE/wp-admin/' | grep -qi \"^content-security-policy: frame-ancestors 'self'\""

# --- 4. the way back ---------------------------------------------------------------------------------------
echo "4. HORNET_DISABLED: nothing is attached, whatever the settings say"
configure "array('hardening' => array('xmlrpc' => true, 'user_enumeration' => true), 'headers' => array('content_type_options' => true))"
wp config set HORNET_DISABLED true --raw >/dev/null
check "no nosniff"                                           sh -c "! curl -s -o /dev/null -D - '$BASE/' | grep -qi '^x-content-type-options:'"
check "?author=1 is back to a redirect"                      status_is 301 "$BASE/?author=1"
check "the pingback header is back on a post"               header_present X-Pingback "$BASE/hello-world/"

# --- 5. a typo does not stop the site ----------------------------------------------------------------------
echo "5. wrong settings: the site keeps running"
reset
configure "array('hardning' => array('xmlrpc' => true))"
check "the site answers"                                     status_is 200 "$BASE/"
check "nothing is hardened"                                  header_present X-Pingback "$BASE/hello-world/"
check "the audit says why"                                   sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status 2>&1 | grep -q 'Unknown Hornet setting \"hardning\"'"

# --- 6. the login throttle ---------------------------------------------------------------------------------
echo "6. login throttle"
# Every request here comes from 127.0.0.1. Without a trusted proxy that is the one address of every visitor.
wrong() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 -b 'wordpress_test_cookie=WP%20Cookie%20check' -H "X-Forwarded-For: $1" --data 'log=admin&pwd=wrong&wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php"; }
right() { curl -s -o /dev/null --max-time 20 -D /tmp/hornet-right.h -b 'wordpress_test_cookie=WP%20Cookie%20check' -H "X-Forwarded-For: $1" --data 'log=admin&pwd=pass&wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php"; }
logged_in() { grep -qi '^set-cookie: wordpress_logged_in' /tmp/hornet-right.h; }
cli() { php -d memory_limit=1G /usr/local/bin/wp --allow-root --path="$WP_DIR" "$@"; }

configure "array('login' => array('throttle' => true, 'max_attempts' => 3))"
for i in 1 2 3 4 5 6; do wrong 203.0.113.9 >/dev/null; done
right 203.0.113.9
check "no trusted proxy: the address is the proxy's, so nobody is locked, even after six failures" logged_in
check "the audit says the throttle cannot tell visitors apart" sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status --fail-on=none | grep -q 'login-protection.*cannot tell visitors apart'"

cli option delete hornet_throttle_problem >/dev/null 2>&1
configure "array('network' => array('trusted_proxies' => array('127.0.0.1')), 'login' => array('throttle' => true, 'max_attempts' => 3, 'allow_ips' => array('203.0.113.50')))"
wrong 203.0.113.9 >/dev/null; wrong 203.0.113.9 >/dev/null
right 203.0.113.9
check "behind a trusted proxy a success is still a success after two failures" logged_in
wrong 203.0.113.9 >/dev/null; wrong 203.0.113.9 >/dev/null; wrong 203.0.113.9 >/dev/null
right 203.0.113.9
check "three failures lock the address: even the right password is refused" sh -c "! grep -qi '^set-cookie: wordpress_logged_in' /tmp/hornet-right.h"
check "the refusal says why" sh -c "curl -s -b 'wordpress_test_cookie=WP%20Cookie%20check' -H 'X-Forwarded-For: 203.0.113.9' --data 'log=admin&pwd=pass&wp-submit=Log+In&testcookie=1' '$BASE/wp-login.php' | grep -q 'Too many failed login attempts'"
right 203.0.113.10
check "another address can still log in" logged_in
for i in 1 2 3 4 5 6; do wrong 203.0.113.50 >/dev/null; done
right 203.0.113.50
check "an address on the allow list is never locked" logged_in
check "a forged left part of the header does not give a locked address another identity" sh -c "curl -s -o /dev/null -D /tmp/hornet-f.h -b 'wordpress_test_cookie=WP%20Cookie%20check' -H 'X-Forwarded-For: 6.6.6.6, 203.0.113.9' --data 'log=admin&pwd=pass&wp-submit=Log+In&testcookie=1' '$BASE/wp-login.php'; ! grep -qi '^set-cookie: wordpress_logged_in' /tmp/hornet-f.h"
stored() { cli eval "require \"$PKG/tests/E2E/hornet-options.php\";"; }
check "the lock is kept in the database"                     sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' eval 'require \"$PKG/tests/E2E/hornet-options.php\";' | grep -q 'hornet_lg_'"
check "the lock is stored under a hash, not the address"     sh -c "! php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' eval 'require \"$PKG/tests/E2E/hornet-options.php\";' | grep -q '203.0.113.9'"

cli hornet unlock 203.0.113.9 >/dev/null
right 203.0.113.9
check "wp hornet unlock lets the address back in" logged_in
for i in 1 2 3; do wrong 203.0.113.11 >/dev/null; done
for i in 1 2 3; do wrong 203.0.113.12 >/dev/null; done
cli hornet unlock --all >/dev/null
right 203.0.113.11
check "wp hornet unlock --all lets everybody back in" logged_in
for i in 1 2 3; do wrong 203.0.113.13 >/dev/null; done
wp config set HORNET_DISABLED true --raw >/dev/null
right 203.0.113.13
check "HORNET_DISABLED switches the lock off" logged_in
wp config delete HORNET_DISABLED >/dev/null 2>&1
cli hornet unlock --all >/dev/null

# --- 7. accounts: the two-factor gate, and how long a login lasts ------------------------------------------
echo "7. accounts"
cli hornet unlock --all >/dev/null 2>&1
location() { curl -s -o /dev/null -D - -b "$JAR" --max-time 20 "$1" | grep -i '^location:' | tr -d '\r'; }

configure "array('two_factor' => array('require_roles' => array('administrator')))"
login
check "a user of the role can still log in with the password: the gate is not on login" sh -c "grep -q wordpress_logged_in '$JAR'"
check "every dashboard page sends them to their profile"       sh -c "curl -s -o /dev/null -D - -b '$JAR' '$BASE/wp-admin/' | grep -i '^location:' | grep -q 'profile.php?hornet_2fa=required'"
check "plugins.php too"                                          sh -c "curl -s -o /dev/null -D - -b '$JAR' '$BASE/wp-admin/plugins.php' | grep -i '^location:' | grep -q 'profile.php?hornet_2fa=required'"
check "the profile stays open"                                   status_is 200 -b "$JAR" "$BASE/wp-admin/profile.php"
check "the profile says why"                                     sh -c "curl -s -b '$JAR' '$BASE/wp-admin/profile.php' | grep -q 'must set up two-factor authentication'"
check "admin-ajax stays open"                                    status_is 200 -b "$JAR" "$BASE/wp-admin/admin-ajax.php?action=rest-nonce"
check "the REST API is not touched"                              status_is 200 "$BASE/wp-json/wp/v2/posts"
check "the audit names the account by number"                    sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status --fail-on=none | grep -q 'two-factor-coverage.*1 administrator has not set up'"

configure "array('two_factor' => array('require_roles' => array('administrator'), 'enforce_after' => '2999-01-01'))"
check "before the date there is a notice and no redirect"        status_is 200 -b "$JAR" "$BASE/wp-admin/"
check "the notice gives the date"                                sh -c "curl -s -b '$JAR' '$BASE/wp-admin/' | grep -q 'before 1 January 2999'"

configure "array('two_factor' => array('require_roles' => array('administrator'), 'enforce_after' => '2000-01-01'))"
check "after the date the redirect is back"                      sh -c "curl -s -o /dev/null -D - -b '$JAR' '$BASE/wp-admin/' | grep -i '^location:' | grep -q 'hornet_2fa=required'"

configure "array('two_factor' => array('require_roles' => array('editor')))"
check "another role is not concerned"                            status_is 200 -b "$JAR" "$BASE/wp-admin/"

configure "array('two_factor' => array('require_roles' => array('administrator')))"
cli eval 'Two_Factor_Core::enable_provider_for_user(1, "Two_Factor_Email");' >/dev/null
check "once a method is set up the dashboard opens"             status_is 200 -b "$JAR" "$BASE/wp-admin/"
check "the audit sees it"                                        sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status --all --fail-on=none | grep -q 'pass.*two-factor-coverage'"
cli eval 'delete_user_meta(1, "_two_factor_enabled_providers"); delete_user_meta(1, "_two_factor_provider");' >/dev/null

configure "array('two_factor' => array('require_roles' => array('administrator')))"
wp config set HORNET_DISABLED true --raw >/dev/null
check "HORNET_DISABLED opens the dashboard again"               status_is 200 -b "$JAR" "$BASE/wp-admin/"
wp config delete HORNET_DISABLED >/dev/null 2>&1

# The login cookie holds the time it expires: name|expiration|token|hash
cookie_expiry() { awk '/wordpress_logged_in/ { print $7 }' "$JAR" | head -1 | sed 's/%7C/|/g' | cut -d'|' -f2; }
expires_in() { echo $(( $(cookie_expiry) - $(date +%s) )); }
in_range() { [ "$1" -ge "$2" ] && [ "$1" -le "$3" ]; }

configure "array('session' => array('lifetime' => 3600, 'remember_lifetime' => 7200))"
login
check "a login lasts the hour the owner chose (not WordPress's two days)" in_range "$(expires_in)" 3500 3660
rm -f "$JAR"
curl -s -o /dev/null -c "$JAR" -b 'wordpress_test_cookie=WP%20Cookie%20check' --max-time 20 --data 'log=admin&pwd=pass&rememberme=forever&wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php"
check "a remembered login lasts the two hours chosen"                 in_range "$(expires_in)" 7100 7260
reset
login
check "without the setting WordPress's own duration is back"          in_range "$(expires_in)" 100000 200000

# --- 8. the scanner: the real WordPress.org, and real files changed on disk ---------------------------------
echo "8. scanner"
# The network between the container and WordPress.org drops now and then: a scan that says "did not answer" is run again (four times at most).
# What the test is about is what the scan does when WordPress.org answers.
scan_try() { n=0; while [ "$n" -lt 4 ]; do php -d memory_limit=1G /usr/local/bin/wp --allow-root --path="$WP_DIR" hornet scan "$@" >/tmp/hornet-scan.out 2>&1; scan_status=$?; grep -q 'did not answer' /tmp/hornet-scan.out || break; n=$((n + 1)); sleep 8; done; }
scan_show() { sed 's/^/        | /' /tmp/hornet-scan.out; return 1; }
# scan_says <text> [scan args]: the scan succeeds and says the text
scan_says() { needle=$1; shift; scan_try "$@"; { [ "$scan_status" -eq 0 ] && grep -qF "$needle" /tmp/hornet-scan.out; } || scan_show; }
# scan_fails_with <text> [scan args]: the scan ends with a failing exit code and says the text
scan_fails_with() { needle=$1; shift; scan_try "$@"; { [ "$scan_status" -ne 0 ] && grep -qF "$needle" /tmp/hornet-scan.out; } || scan_show; }
core_file="$WP_DIR/wp-includes/version.php"
cp "$core_file" /tmp/hornet-version.php.orig
cli option delete hornet_scan >/dev/null 2>&1
reset

check "before any scan the audit says it does not know"            sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status --fail-on=none | grep -q 'core-integrity.*never scanned'"
check "an untouched core matches WordPress.org"                     scan_says 'Nothing found'
check "the audit now shows the scan"                                sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status --all --fail-on=none | grep -q 'pass.*core-integrity'"
check "the scan is not autoloaded"                                  sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' eval 'require \"$PKG/tests/E2E/scan-autoload.php\";' | grep -qE '^(off|no|auto-off)$'"

echo '<?php // planted by the E2E test' > "$WP_DIR/wp-includes/hornet-e2e-shell.php"
check "a script planted in wp-includes fails the scan, and the exit code says so" scan_fails_with 'wp-includes/hornet-e2e-shell.php'
check "the audit reports it too"                                    sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status --fail-on=none | grep -q 'core-integrity'"
check "the planted file is still there: Hornet deletes nothing"     test -f "$WP_DIR/wp-includes/hornet-e2e-shell.php"
rm -f "$WP_DIR/wp-includes/hornet-e2e-shell.php"

echo "// changed by the E2E test" >> "$core_file"
check "a changed core file fails the scan"                          scan_fails_with 'Changed: wp-includes/version.php'
check "the changed file is not repaired"                            sh -c "tail -1 '$core_file' | grep -q 'changed by the E2E test'"
configure "array('scanner' => array('allow_modified' => array('wp-includes/version.php')))"
check "an allowed file may differ"                                  scan_says 'Nothing found'
echo "// and then something else" >> "$core_file"
check "an allowed file that changes again is reported"              scan_fails_with 'Changed since first seen: wp-includes/version.php'
check "it keeps being reported at the next scan"                    scan_fails_with 'Open findings'
check "--repin accepts the file as it is"                           scan_says 'Nothing found' --repin
cp /tmp/hornet-version.php.orig "$core_file"
reset

# --- 8b. the plugins: the real list of downloads.wordpress.org, and a plugin it does not know ------------------
echo "8b. plugins"
akismet="$WP_DIR/wp-content/plugins/akismet"
check "the plugins that WordPress.org lists match its list"          scan_says 'Nothing found'
check "the audit shows the plugin result"                            sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status --all --fail-on=none | grep -q 'pass.*plugin-integrity'"
cp "$akismet/akismet.php" /tmp/hornet-akismet.orig
echo "// changed by the E2E test" >> "$akismet/akismet.php"
check "a changed plugin file fails the scan"                         scan_fails_with 'Changed: akismet/akismet.php'
cp /tmp/hornet-akismet.orig "$akismet/akismet.php"
echo '<?php // planted by the E2E test' > "$akismet/hornet-e2e-shell.php"
check "a script planted in a plugin fails the scan"                  scan_fails_with 'Script that does not belong: akismet/hornet-e2e-shell.php'
check "it is still there: Hornet deletes nothing"                    test -f "$akismet/hornet-e2e-shell.php"
rm -f "$akismet/hornet-e2e-shell.php"
check "back to normal, the scan is clean again"                      scan_says 'Nothing found'

mkdir -p "$WP_DIR/wp-content/plugins/hornet-e2e-premium"
printf '<?php\n/*\nPlugin Name: Hornet E2E premium\nVersion: 3.1\n*/\n' > "$WP_DIR/wp-content/plugins/hornet-e2e-premium/premium.php"
check "a plugin WordPress.org does not know gets a first look"       scan_says 'First look kept for 1 plugin(s)'
check "the second scan compares with it"                             scan_says '2 plugin(s) match WordPress.org, 1 match what was first seen.'
check "the first look is not autoloaded"                             sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' eval 'global \$wpdb; echo \$wpdb->get_var(\"SELECT autoload FROM {\$wpdb->options} WHERE option_name = \\\"hornet_baseline\\\"\");' | grep -qE '^(off|no|auto-off)\$'"
echo "// changed by the E2E test" >> "$WP_DIR/wp-content/plugins/hornet-e2e-premium/premium.php"
check "a change in it is reported"                                   scan_fails_with 'Changed: hornet-e2e-premium/premium.php'
check "--repin takes the plugin as it is now"                        scan_says 'First look kept' --repin
check "and the scan after it is clean"                               scan_says '2 plugin(s) match WordPress.org, 1 match what was first seen.'
rm -rf "$WP_DIR/wp-content/plugins/hornet-e2e-premium"
cli option delete hornet_baseline >/dev/null 2>&1

# --- 8c. the files of the content folder, and the schedule ----------------------------------------------------
echo "8c. suspicious files"
content="$WP_DIR/wp-content"
mkdir -p "$content/uploads/2026/11" "$content/themes/e2e"
check "a fresh content folder has nothing suspicious"                scan_says 'Nothing found'
echo '<?php echo "hello";' > "$content/uploads/2026/11/logo.php"
echo 'x' > "$content/uploads/2026/11/pic.php.jpg"
printf 'AddHandler application/x-httpd-php .jpg\n' > "$content/uploads/.htaccess"
printf '<?php eval($_POST["x"]);' > "$content/themes/e2e/inc.php"
check "a script in the uploads fails the scan"                       scan_fails_with 'uploads/2026/11/logo.php: a PHP file in the uploads folder'
check "a name with a PHP extension in the middle is told"            grep -q 'pic.php.jpg' /tmp/hornet-scan.out
check "a server setting that runs pictures as PHP is told"           grep -q 'uploads/.htaccess' /tmp/hornet-scan.out
check "a web shell in a theme is told, with its score"               grep -q 'themes/e2e/inc.php: reads like a web shell (score 7)' /tmp/hornet-scan.out
check "the audit shows it"                                           sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status --fail-on=none | grep -q 'suspicious-files'"
check "nothing was deleted"                                          sh -c "test -f '$content/uploads/2026/11/logo.php' && test -f '$content/themes/e2e/inc.php'"
configure "array('scanner' => array('ignore_paths' => array('uploads', 'themes/e2e')))"
check "folders the owner leaves alone are left alone"                scan_says 'Nothing found'
reset

configure "array('scanner' => array('schedule' => 'daily'))"
check "the schedule puts the scan in the cron"                       sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' cron event list --format=csv | grep -q 'pollora/hornet/scheduled_scan'"
cli option delete hornet_scan >/dev/null 2>&1
# The event is made due, and WordPress's own cron entry point runs it, over HTTP, under a PHP that stops a request at 20 seconds.
# WordPress starts the cron with a request to its own address: the site is pointed at the limited server for the time of this test, so that all the work is done there.
CRON_BASE="http://127.0.0.1:$CRON_PORT"
cli option update home "$CRON_BASE" >/dev/null
cli option update siteurl "$CRON_BASE" >/dev/null
cli eval 'wp_clear_scheduled_hook("pollora/hornet/scheduled_scan"); wp_schedule_event(time() - 60, "daily", "pollora/hornet/scheduled_scan");' >/dev/null
check "wp-cron.php answers"                                          status_is 200 --max-time 120 "http://127.0.0.1:$CRON_PORT/wp-cron.php?doing_wp_cron=1"
lock_gone() { n=0; while php -d memory_limit=1G /usr/local/bin/wp --allow-root --path="$WP_DIR" option get hornet_scan_lock >/dev/null 2>&1; do n=$((n + 1)); [ "$n" -gt 30 ] && { echo "        | the lock is still held after 60 seconds"; tail -5 /tmp/e2e-cron.log | sed 's/^/        | /'; return 1; }; sleep 2; done; }
check "the scan lets go of its lock"                                 lock_gone
scan_covered() { out=$(php -d memory_limit=1G /usr/local/bin/wp --allow-root --path="$WP_DIR" hornet status --all --fail-on=none 2>&1); for id in core-integrity plugin-integrity suspicious-files; do echo "$out" | grep "$id" | grep -qE 'never scanned|did not cover' && { echo "        | $id has no result"; echo "$out" | grep -E 'core-integrity|plugin-integrity|suspicious-files' | cut -c1-160 | sed 's/^/        | /'; tail -15 /tmp/e2e-cron.log | sed 's/^/        | log: /'; php -d memory_limit=1G /usr/local/bin/wp --allow-root --path="$WP_DIR" cron event list --format=csv 2>&1 | grep -i hornet | sed 's/^/        | cron: /'; return 1; }; done; echo "$out" | grep -q 'suspicious-files'; }
check "the scheduled scan, under a 20 second PHP, stored a result for every scan" scan_covered
cli option update home "$BASE" >/dev/null
cli option update siteurl "$BASE" >/dev/null
configure "array('scanner' => array('schedule' => 'off'))"
check "turned off, the scan leaves the cron"                         sh -c "! php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' cron event list --format=csv | grep -q 'pollora/hornet/scheduled_scan'"
reset
rm -rf "$content/uploads/2026" "$content/uploads/.htaccess" "$content/themes/e2e"
cli option delete hornet_scan >/dev/null 2>&1

# --- 8d. the themes, and what the site gives away -------------------------------------------------------------
echo "8d. themes and probes"
themes="$WP_DIR/wp-content/themes"
theme=$(ls "$themes" | grep -v '\.php$' | head -1)
cli option delete hornet_baseline_themes >/dev/null 2>&1
scan_try >/dev/null
check "the first scan keeps a first look of the themes"              sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' option get hornet_baseline_themes | grep -q 'style.css'"
check "the second compares with it"                                  scan_says 'theme(s) match what was first seen'
echo "/* changed by the E2E test */" >> "$themes/$theme/style.css"
check "a changed theme file is reported"                             scan_fails_with "Changed: $theme/style.css"
configure "array('scanner' => array('own_themes' => array('$theme')))"
check "a theme that is the owner's is left out"                      scan_says 'Nothing found'
reset
cli option delete hornet_baseline_themes >/dev/null 2>&1
sed -i '$ d' "$themes/$theme/style.css"

check "a clean site gives nothing away"                              scan_says 'None of the'
printf 'APP_KEY=base64:abc\nDB_PASSWORD=hunter2-e2e-secret\n' > "$WP_DIR/.env"
printf '[25-Nov-2026 10:00:00 UTC] PHP Warning:  Undefined variable in /var/www/html/wp-content/themes/x/a.php on line 3\n' > "$WP_DIR/wp-content/debug.log"
check "a .env that the web server serves is reported"                scan_fails_with '/.env answers, and it is environment variables'
check "the debug log in the content folder is reported"              grep -q '/wp-content/debug.log answers' /tmp/hornet-scan.out
check "the scan does not print what the .env holds"                  sh -c "! grep -q 'hunter2' /tmp/hornet-scan.out"
check "the audit shows it, and not the secret"                       sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status --fail-on=none | grep -q 'exposed-files' && ! php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status --fail-on=none | grep -q hunter2"
rm -f "$WP_DIR/.env" "$WP_DIR/wp-content/debug.log"
check "the files are not touched by the scan"                        scan_says 'None of the'

mkdir -p "$WP_DIR/wp-content/uploads"
check "nothing is written in the uploads unless the owner asks"      sh -c "ls '$WP_DIR'/wp-content/uploads | grep -c hornet-probe | grep -q '^0\$'"
configure "array('scanner' => array('probe_uploads' => true))"
check "the server runs PHP in the uploads: the probe says so"        scan_fails_with 'The server runs PHP that sits in the uploads folder'
check "the probe file is gone"                                       sh -c "ls '$WP_DIR'/wp-content/uploads | grep -c hornet-probe | grep -q '^0\$'"
printf "<?php echo strrev('abc');\n" > "$WP_DIR/wp-content/uploads/hornet-probe-aaaaaaaaaaaaaaaa.php"
scan_try >/dev/null
check "a probe file that a dead run left is swept"                   sh -c "! test -f '$WP_DIR/wp-content/uploads/hornet-probe-aaaaaaaaaaaaaaaa.php'"
reset
configure "array('scanner' => array('probes' => false))"
check "with the probes off nothing is asked of the site"             sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet scan --format=json | grep -vq '\"check\": \"exposed-files\"'"
reset
cli option delete hornet_scan >/dev/null 2>&1
cli option delete hornet_baseline_themes >/dev/null 2>&1

# --- 8e. known vulnerabilities: the real feed, opt-in -------------------------------------------------------
echo "8e. vulnerabilities"
akismet="$WP_DIR/wp-content/plugins/akismet/akismet.php"
vuln_row() { php -d memory_limit=1G /usr/local/bin/wp --allow-root --path="$WP_DIR" hornet status --all --fail-on=none | grep -q 'vulnerabilities'; }
check "off by default: no check of the vulnerabilities in the audit"  sh -c "! php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status --all --fail-on=none | grep -q 'vulnerabilities'"
check "off by default: the scan does not ask the feed"               sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet scan --format=json | grep -vq '\"check\": \"vulnerabilities\"'"
configure "array('vulnerabilities' => array('enabled' => true))"
check "the audit has the check once the owner opted in"              vuln_row
check "the real feed knows no vulnerability in what is installed, or says which" scan_says 'vulnerabilities'
cp "$akismet" /tmp/hornet-akismet-header.orig
sed -i -E 's/^([ *]*Version:).*/\1 3.1.4/' "$akismet"
check "the header of the plugin now says 3.1.4"                       grep -qE '^[ *]*Version: 3.1.4' "$akismet"
check "an old version of a plugin: the real feed says its vulnerability" scan_fails_with 'CVE-2015-9357'
check "it says where to update to"                                   grep -q 'fixed in 3.1.5' /tmp/hornet-scan.out
check "it says which plugin and which version"                       grep -q 'akismet 3.1.4 (plugin)' /tmp/hornet-scan.out
check "the answer is kept: asked once, not each time"                sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' eval 'echo get_site_transient(\"hornet_vuln_\".md5(\"plugin:akismet:3.1.4\")) ? \"cached\" : \"none\";' | grep -q cached"
cp /tmp/hornet-akismet-header.orig "$akismet"
configure "array('vulnerabilities' => array('enabled' => true), 'disabled_checks' => array('vulnerabilities'))"
check "disabled_checks stops the lookup and the check"               sh -c "! php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' hornet status --all --fail-on=none | grep -q 'vulnerabilities'"
reset
cli option delete hornet_scan >/dev/null 2>&1

# --- 8f. the screen's "Scan now" when the cron cannot start by itself: the system cron runs it --------------------
echo "8f. scan now, system cron"
configure "array('scanner' => array('probes' => false))"
cli option delete hornet_scan hornet_scan_state >/dev/null 2>&1
# The loopback request that WordPress makes to start the cron goes nowhere: only the system cron (wp cron event run) can run the event
cli option update home "http://127.0.0.1:9" >/dev/null
cli option update siteurl "http://127.0.0.1:9" >/dev/null
check "an administrator asks for a scan: 202, and it waits"          sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' eval 'wp_set_current_user(1); \$r = rest_do_request(new WP_REST_Request(\"POST\", \"/hornet/v1/scan\")); echo \$r->get_status();' | grep -q '^202\$'"
check "the state says it is queued"                                  sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' option get hornet_scan_state | grep -q queued"
check "a second request is refused while it waits"                   sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' eval 'wp_set_current_user(1); \$r = rest_do_request(new WP_REST_Request(\"POST\", \"/hornet/v1/scan\")); echo \$r->get_status();' | grep -q '^409\$'"
cli option update home "$BASE" >/dev/null
cli option update siteurl "$BASE" >/dev/null
cli cron event run --due-now >/dev/null 2>&1
check "the system cron ran it: the state is idle again"               sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' option get hornet_scan_state | grep -q idle"
check "and a scan is stored"                                         sh -c "php -d memory_limit=1G /usr/local/bin/wp --allow-root --path='$WP_DIR' option get hornet_scan | grep -q 'core-integrity'"
reset
cli option delete hornet_scan hornet_scan_state >/dev/null 2>&1

wp config set HORNET_DISABLED true --raw >/dev/null
check "HORNET_DISABLED: the scan does not run, and asks nothing of the network" scan_fails_with 'nothing was scanned'
wp config delete HORNET_DISABLED >/dev/null 2>&1
check "the core is back as it was"                                  scan_says 'Nothing found'
cli option delete hornet_scan >/dev/null 2>&1

reset
echo
if [ "$failed" -eq 0 ]; then echo "All assertions passed."; else echo "Some assertions failed."; fi
exit "$failed"

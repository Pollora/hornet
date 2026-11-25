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
for i in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null "$BASE/" && break; sleep 1; done

# --- 1. nothing configured: nothing changes ----------------------------------------------------------------
echo "1. Hornet installed, nothing turned on: the site is as WordPress made it"
check "the front sends no nosniff"                 sh -c "! curl -s -o /dev/null -D - '$BASE/' | grep -qi '^x-content-type-options:'"
check "?author=1 still redirects to the author page" status_is 301 "$BASE/?author=1"
check "the REST API still lists the users"          body_has '"slug":"admin"' "$BASE/wp-json/wp/v2/users"
check "the pingback header is still there on a post"  header_present X-Pingback "$BASE/hello-world/"
check "the generator tag is still there"            body_has 'name="generator"' "$BASE/"
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

wp config set HORNET_DISABLED true --raw >/dev/null
check "HORNET_DISABLED: the scan does not run, and asks nothing of the network" scan_fails_with 'nothing was scanned'
wp config delete HORNET_DISABLED >/dev/null 2>&1
check "the core is back as it was"                                  scan_says 'Nothing found'
cli option delete hornet_scan >/dev/null 2>&1

reset
echo
if [ "$failed" -eq 0 ]; then echo "All assertions passed."; else echo "Some assertions failed."; fi
exit "$failed"

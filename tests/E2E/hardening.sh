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

reset
echo
if [ "$failed" -eq 0 ]; then echo "All assertions passed."; else echo "Some assertions failed."; fi
exit "$failed"

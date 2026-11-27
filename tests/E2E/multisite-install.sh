#!/bin/sh
# Installs the throwaway network that tests/E2E/multisite.sh is run against (sourced by it, inside the web container):
# the super administrator `admin`, `rootonly` (super administrator with no role on any site), `mainadmin` (administrator
# of the main site only), `siteadmin` (administrator of the second site only), all with the password `pass`, the sites
# 1 (main), 2 (two) and 3 (three), and the official Two-Factor plugin active on the network.
cd "$WP_DIR" || exit 1
[ -f wp-config.php ] || wp config create --dbhost="$HMS_DB" --dbname=wordpress --dbuser=root --skip-check --quiet
if ! wp core is-installed --network 2>/dev/null; then
  # shellcheck disable=SC2086
  wp core multisite-install --url="http://$HMS_DOMAIN" --title=t --admin_user=admin --admin_password=pass --admin_email=admin@a.test --skip-email $HMS_SUBDOMAINS --quiet
  cat > .htaccess <<'HTACCESS'
RewriteEngine On
RewriteBase /
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteRule ^index\.php$ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]
RewriteRule . index.php [L]
HTACCESS
  wp site create --slug=two --title=two --quiet
  wp site create --slug=three --title=three --quiet
  wp user create mainadmin mainadmin@a.test --role=administrator --user_pass=pass --quiet
  wp user create siteadmin siteadmin@a.test --role=subscriber --user_pass=pass --quiet
  wp user remove-role siteadmin subscriber --quiet
  wp user set-role siteadmin administrator --url="$(site_url_of 2)" --quiet
  wp user create rootonly rootonly@a.test --role=subscriber --user_pass=pass --quiet
  wp user remove-role rootonly subscriber --quiet
  wp super-admin add rootonly --quiet
  wp plugin install two-factor --activate-network --quiet
  for url in "$(site_url_of 1)" "$(site_url_of 2)" "$(site_url_of 3)"; do wp rewrite structure '/%postname%/' --url="$url" --quiet 2>/dev/null; done
fi
mkdir -p wp-content/mu-plugins wp-content/uploads
printf '<?php\nrequire "/pkg/vendor/autoload.php";\nrequire "/pkg/stubs/pollora-hornet.php";\n' > wp-content/mu-plugins/hornet.php
# The mails are not sent: what they were about is kept, and by which site
cat > wp-content/mu-plugins/zz-mail.php <<'PHP'
<?php
add_filter('pre_wp_mail', static fn ($short, array $mail): bool => file_put_contents('/tmp/hms-mail.log', get_current_blog_id().' '.$mail['subject']."\n", FILE_APPEND) !== false, 10, 2);
PHP

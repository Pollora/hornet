<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Server;

use Pollora\Hornet\Domain\Exceptions\ServerRulesException;

/**
 * Rules for the web server that no plugin can apply: the request never reaches PHP. Printed, never written: a server configuration is the
 * owner's, a mistake in it takes the site down, and a plugin that edits it can be made to edit it.
 *
 * What they say: no PHP is run from the uploads folder (the way a webshell is usually planted), no dotfile (`.env`, `.git`) is served, nor
 * the files that should never be public (`wp-config.php`, `composer.json`, a dump, a backup, a log); and, when asked, the headers that
 * never change.
 *
 * The folder is a URL path, which is what both servers match on. It is checked: it goes into a configuration file.
 */
final class ServerRules
{
    private const string PATH = '#^/(?:[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*)(?:/[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*){0,6}$#D';

    private const string CODE = 'php\d?|phtml|phar|pht|phps';

    private const string NEVER_PUBLIC = 'wp-config(?:-sample)?\.php|composer\.(?:json|lock)|package(?:-lock)?\.json|auth\.json|\.htpasswd';

    private const string DUMPS = 'sql|sqlite|bak|old|orig|swp|log|ini';

    /**
     * @throws ServerRulesException
     */
    public static function for(string $server, string $uploads, bool $headers = false): string
    {
        $uploads = rtrim($uploads, '/');

        if (preg_match(self::PATH, $uploads) !== 1 || str_contains($uploads, '..')) {
            throw ServerRulesException::badPath($uploads);
        }

        return match ($server) {
            'nginx' => self::nginx($uploads, $headers),
            'apache' => self::apache($uploads, $headers),
            default => throw ServerRulesException::unknownServer($server),
        };
    }

    private static function nginx(string $uploads, bool $headers): string
    {
        $out = <<<NGINX
# Rules for nginx, written by `wp hornet server-config nginx`. Nothing was changed on this server: read them, put them in your server
# block (before the `location ~ \\.php\$` block of PHP, which would otherwise answer first), run `nginx -t`, then reload.

# No PHP from the uploads folder, nor a double extension (shell.php.jpg).
# ^~ ends the search among the regular expressions: a PHP block further down never sees these files.
location ^~ {$uploads}/ {
    location ~* \\.(?:%s)(?:\\.|\$) { return 403; }
}

# No dotfile (.env, .git, .htaccess), except .well-known (certificates, security.txt).
location ~ /\\.(?!well-known/) { return 403; }

# Files that are never public.
location ~* (?:^|/)(?:%s)\$ { return 403; }
location ~* \\.(?:%s)\$ { return 403; }


NGINX;
        $out = sprintf($out, self::CODE, self::NEVER_PUBLIC, self::DUMPS);

        if ($headers) {
            $out .= <<<'NGINX'
# Headers that do not change. If Hornet's own `headers` settings are on, do not set them twice.
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;

NGINX;
        }

        return $out;
    }

    private static function apache(string $uploads, bool $headers): string
    {
        $out = <<<APACHE
# Rules for Apache 2.4, written by `wp hornet server-config apache`. Nothing was changed on this server: read them, put them in the
# virtual host (or in .htaccess, where AllowOverride Limit and FileInfo allow it), run `apachectl configtest`, then reload.

# No PHP from the uploads folder, nor a double extension (shell.php.jpg runs where .php is mapped anywhere in a name).
<LocationMatch "^{$uploads}/.*\\.(?i:%s)(?:\\.|\$)">
    Require all denied
</LocationMatch>

# No dotfile (.env, .git, .htaccess). .well-known is a folder: it is not matched.
<FilesMatch "^\\.">
    Require all denied
</FilesMatch>

# Files that are never public.
<FilesMatch "^(?:%s)\$">
    Require all denied
</FilesMatch>
<FilesMatch "\\.(?i:%s)\$">
    Require all denied
</FilesMatch>

APACHE;
        $out = sprintf($out, self::CODE, self::NEVER_PUBLIC, self::DUMPS);

        if ($headers) {
            $out .= <<<'APACHE'
# Headers that do not change (mod_headers). If Hornet's own `headers` settings are on, do not set them twice.
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
</IfModule>

APACHE;
        }

        return $out;
    }
}

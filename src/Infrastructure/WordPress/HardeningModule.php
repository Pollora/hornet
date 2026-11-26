<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Infrastructure\Config\HardeningSettings;

/**
 * Closes what the owner asked to close. Each piece is one filter or one action and is safe to add twice, so it
 * does not matter that pollora/headless may do the same.
 *
 * - **xmlrpc**: no method answers (`xmlrpc_enabled` off only refuses the methods that need a login: pingbacks would
 *   still answer), and the `X-Pingback` header and the RSD link, which advertise it, go.
 * - **user_enumeration**: `?author=N` is a 404 (it must run before `redirect_canonical`, which turns it into
 *   `/author/<login>/`), the REST user routes disappear for visitors, and so do the users sitemap and the author of oEmbed.
 * - **hide_version**: no generator tag.
 * - **file_editor**: the capabilities of the theme and plugin editor are refused to everyone (what `DISALLOW_FILE_EDIT`
 *   does, which a plugin cannot define: `wp-config.php` loads first).
 *
 * @internal
 */
final readonly class HardeningModule implements Module
{
    public function __construct(private HardeningSettings $settings) {}

    public function register(HookRegistrar $hooks): void
    {
        if ($this->settings->xmlRpc) {
            $hooks->addFilter('xmlrpc_enabled', static fn (): bool => false);
            $hooks->addFilter('xmlrpc_methods', static fn (): array => []);
            $hooks->addFilter('wp_headers', self::withoutPingbackHeader(...));
            $hooks->addAction('init', static function (): void {
                remove_action('wp_head', 'rsd_link');
                remove_action('wp_head', 'wlwmanifest_link');
            });
        }

        if ($this->settings->userEnumeration) {
            // Before redirect_canonical (priority 10), which would reveal the author's login in the address
            $hooks->addAction('template_redirect', self::refuseAuthorQuery(...), 1);
            $hooks->addFilter('rest_endpoints', self::withoutUserRoutes(...));
            $hooks->addFilter('wp_sitemaps_add_provider', static fn (mixed $provider, string $name): mixed => $name === 'users' ? false : $provider, 10, 2);
            $hooks->addFilter('oembed_response_data', self::withoutOEmbedAuthor(...));
        }

        if ($this->settings->hideVersion) {
            $hooks->addFilter('the_generator', static fn (): string => '');
            $hooks->addAction('init', static function (): void {
                remove_action('wp_head', 'wp_generator');
            });
        }

        if ($this->settings->fileEditor) {
            $hooks->addFilter('map_meta_cap', self::refuseFileEditing(...), 10, 2);
        }
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public static function withoutPingbackHeader(array $headers): array
    {
        unset($headers['X-Pingback']);

        return $headers;
    }

    /**
     * `?author=1` from a visitor: a 404, whatever the site's permalinks.
     */
    public static function refuseAuthorQuery(): void
    {
        $author = $_GET['author'] ?? null;

        if (is_user_logged_in() || ! is_string($author) || preg_match('/\A\d+\z/', $author) !== 1) {
            return;
        }

        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
    }

    /**
     * @param  array<string, mixed>  $endpoints
     * @return array<string, mixed>
     */
    public static function withoutUserRoutes(array $endpoints): array
    {
        if (is_user_logged_in()) {
            return $endpoints;
        }

        foreach (array_keys($endpoints) as $route) {
            if (str_starts_with((string) $route, '/wp/v2/users')) {
                unset($endpoints[$route]);
            }
        }

        return $endpoints;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withoutOEmbedAuthor(array $data): array
    {
        unset($data['author_name'], $data['author_url']);

        return $data;
    }

    /**
     * @param  list<string>  $caps
     * @return list<string>
     */
    public static function refuseFileEditing(array $caps, string $cap): array
    {
        return in_array($cap, ['edit_files', 'edit_plugins', 'edit_themes'], true) ? ['do_not_allow'] : $caps;
    }
}

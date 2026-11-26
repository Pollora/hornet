<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Throwable;

/**
 * Reads the settings, wherever they are: the one place that knows, so that the web, WP-CLI and the cron agree.
 *
 * On Pollora they are the application's `pollora.hornet` configuration. Elsewhere, define them in wp-config.php (or in a Bedrock environment file) before WordPress loads:
 *
 *     define('HORNET_CONFIG', ['ignore' => [['check' => 'file-mods', 'reason' => 'Updates come from the dashboard on this site.']]]);
 *
 * `HORNET_DISABLED` is the kill switch: defined as true, Hornet attaches nothing. It is the way back when a rule locks someone out.
 * `HORNET_CONFIG`, when defined, wins over the application's configuration. On a multisite network, `sites` holds the settings of one
 * site, keyed by its ID, over the shared ones.
 */
final class SettingsLoader
{
    /**
     * @param  array<mixed>|null  $application  the configuration of the application, when the caller holds it
     */
    public static function load(int $siteId = 1, ?array $application = null): Settings
    {
        $config = defined('HORNET_CONFIG') && is_array(HORNET_CONFIG) ? HORNET_CONFIG : ($application ?? self::application());

        if (defined('HORNET_DISABLED') && HORNET_DISABLED === true) {
            $config['enabled'] = false;
        }

        return Settings::fromArray(self::merge($config, $siteId));
    }

    /**
     * What a Laravel application (Pollora) configured, when there is one and it is ready; nothing otherwise.
     *
     * @return array<mixed>
     */
    private static function application(): array
    {
        if (! function_exists('config')) {
            return [];
        }

        try {
            $config = config('pollora.hornet');
        } catch (Throwable) {
            return [];
        }

        return is_array($config) ? $config : [];
    }

    /**
     * The settings of one site: the shared ones, then those of `sites[$siteId]`.
     *
     * @param  array<mixed>  $config
     * @return array<mixed>
     */
    public static function merge(array $config, int $siteId): array
    {
        $sites = $config['sites'] ?? [];
        unset($config['sites']);

        if (is_array($sites) && isset($sites[$siteId]) && is_array($sites[$siteId])) {
            $config = [...$config, ...$sites[$siteId]];
        }

        return $config;
    }
}

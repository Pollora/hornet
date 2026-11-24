<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

/**
 * Reads the settings of a WordPress that is not Pollora: Bedrock, or a classic install.
 *
 * Define them in wp-config.php (or in a Bedrock environment file) before WordPress loads:
 *
 *     define('HORNET_CONFIG', ['ignore' => [['check' => 'file-mods', 'reason' => 'Updates come from the dashboard on this site.']]]);
 *
 * `HORNET_DISABLED` is the kill switch: defined as true, Hornet attaches nothing. It is the way back when a rule locks someone out.
 * On a multisite network, `sites` holds the settings of one site, keyed by its ID, over the shared ones.
 */
final class SettingsLoader
{
    public static function load(int $siteId = 1): Settings
    {
        $config = defined('HORNET_CONFIG') && is_array(HORNET_CONFIG) ? HORNET_CONFIG : [];

        if (defined('HORNET_DISABLED') && HORNET_DISABLED === true) {
            $config['enabled'] = false;
        }

        return Settings::fromArray(self::merge($config, $siteId));
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

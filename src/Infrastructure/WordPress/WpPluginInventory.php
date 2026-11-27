<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Domain\Scalar;
use Pollora\Hornet\Domain\Scan\InstalledPlugin;
use Pollora\Hornet\Domain\Scan\PluginInventory;

/**
 * The plugins WordPress knows of, through `get_plugins()`. A single-file plugin (hello.php) has no folder: it is left out.
 *
 * @internal
 */
final class WpPluginInventory implements PluginInventory
{
    public function plugins(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $plugins = [];

        foreach (get_plugins() as $file => $data) {
            $slug = dirname($file);
            $version = Scalar::string($data['Version'] ?? null);

            if ($slug !== '.' && $version !== '' && ! isset($plugins[$slug])) {
                $plugins[$slug] = new InstalledPlugin($slug, $version, Scalar::string($data['Name'] ?? null), Scalar::string($data['UpdateURI'] ?? null));
            }
        }

        return array_values($plugins);
    }
}

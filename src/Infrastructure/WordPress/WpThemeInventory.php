<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Domain\Scan\InstalledPlugin;
use Pollora\Hornet\Domain\Scan\PluginInventory;

/**
 * The themes WordPress knows of, active or not, in the themes directory. The themes the owner writes themselves
 * (`scanner.own_themes`) are left out: they change with every deployment, and a report on each change would say nothing.
 */
final readonly class WpThemeInventory implements PluginInventory
{
    /**
     * @param  list<string>  $own  slugs (folder names) of the themes written for this site
     */
    public function __construct(private array $own = []) {}

    public function plugins(): array
    {
        $themes = [];

        foreach (wp_get_themes() as $slug => $theme) {
            $slug = (string) $slug;

            if (! in_array($slug, $this->own, true)) {
                $version = $theme->get('Version');
                $themes[] = new InstalledPlugin($slug, is_string($version) && $version !== '' ? $version : '0');
            }
        }

        return $themes;
    }
}

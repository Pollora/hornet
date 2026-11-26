<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Scan\InstalledPlugin;
use Pollora\Hornet\Domain\Scan\PluginInventory;

/**
 * Plugins with the headers a test gives them.
 */
final readonly class ListedPlugins implements PluginInventory
{
    /**
     * @param  list<InstalledPlugin>  $plugins
     */
    public function __construct(private array $plugins) {}

    public function plugins(): array
    {
        return $this->plugins;
    }
}

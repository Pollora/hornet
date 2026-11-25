<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Scan\InstalledPlugin;
use Pollora\Hornet\Domain\Scan\PluginInventory;

final readonly class ArrayPluginInventory implements PluginInventory
{
    /**
     * @param  array<string, string>  $versions  version by slug
     */
    public function __construct(private array $versions) {}

    public function plugins(): array
    {
        $plugins = [];

        foreach ($this->versions as $slug => $version) {
            $plugins[] = new InstalledPlugin((string) $slug, $version);
        }

        return $plugins;
    }
}

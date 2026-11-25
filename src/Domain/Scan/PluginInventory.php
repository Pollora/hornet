<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

interface PluginInventory
{
    /**
     * The plugins that have a folder of their own, active or not (an inactive plugin can still be run by a request that names its file).
     *
     * @return list<InstalledPlugin>
     */
    public function plugins(): array;
}

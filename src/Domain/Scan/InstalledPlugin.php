<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * A plugin that lives in its own folder of the plugins directory. The folder name is its slug on WordPress.org.
 *
 * @internal
 */
final readonly class InstalledPlugin
{
    public function __construct(
        public string $slug,
        public string $version,
    ) {}
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * For what WordPress.org publishes no checksums of (the themes): there is no list, and the first look is the only reference.
 */
final readonly class NoPublishedChecksums implements PluginChecksums
{
    public function checksums(string $slug, string $version): ?array
    {
        return null;
    }
}

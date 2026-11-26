<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * What the WordPress.org directory says of a folder name.
 *
 * @internal
 */
enum PluginListingStatus: string
{
    case Listed = 'listed';
    case Closed = 'closed';
    case Absent = 'absent';
}

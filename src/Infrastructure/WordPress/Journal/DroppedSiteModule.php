<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Journal;

use Pollora\Hornet\Domain\Scalar;
use Pollora\Hornet\Infrastructure\WordPress\Database;
use Pollora\Hornet\Infrastructure\WordPress\HookRegistrar;
use Pollora\Hornet\Infrastructure\WordPress\Module;

/**
 * The journal's table goes with the site that is deleted from a network. A site is deleted from another one (the network
 * administration), where the journal may be off while it was on for the deleted site: this is attached whether it is on or not.
 *
 * @internal
 */
final class DroppedSiteModule implements Module
{
    public function register(HookRegistrar $hooks): void
    {
        $hooks->addFilter('wpmu_drop_tables', $this->tables(...), 10, 2);
    }

    /**
     * @param  mixed  $tables  the tables WordPress is about to drop with a site
     * @return array<mixed>
     */
    public function tables(mixed $tables, mixed $siteId = 0): array
    {
        return [...(is_array($tables) ? $tables : []), Database::connection()->get_blog_prefix(Scalar::int($siteId)).'hornet_journal'];
    }
}

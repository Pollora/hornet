<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use Pollora\Hornet\Domain\Scan\Pins;

/**
 * One thing the scanner looks at (the core files, the plugins, the suspicious files). It may read the disk and ask the network.
 */
interface ScanTask
{
    /** Stable identifier, kebab-case: the id of the check that shows its result, and of its finding. */
    public function id(): string;

    /**
     * @param  Pins|null  $pinned  what the last scan pinned for this task
     * @param  bool  $repin  the owner accepts the allowed files as they are now
     */
    public function run(?Pins $pinned, bool $repin): TaskResult;
}

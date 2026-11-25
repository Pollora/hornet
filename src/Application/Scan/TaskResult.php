<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Scan\Pins;

/**
 * What a scan task found, and the pins it wants kept for the next scan.
 */
final readonly class TaskResult
{
    public function __construct(
        public Finding $finding,
        public ?Pins $pins = null,
    ) {}
}

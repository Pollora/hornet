<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * Where the last scan is kept.
 *
 * @internal
 */
interface ScanStore
{
    public function last(): ?ScanSnapshot;

    public function save(ScanSnapshot $snapshot): void;
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

interface ScanStateStore
{
    public function get(): ScanState;

    public function save(ScanState $state): void;
}

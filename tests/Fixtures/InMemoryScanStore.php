<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Domain\Scan\ScanStore;

final class InMemoryScanStore implements ScanStore
{
    public int $saves = 0;

    public function __construct(public ?ScanSnapshot $snapshot = null) {}

    public function last(): ?ScanSnapshot
    {
        return $this->snapshot;
    }

    public function save(ScanSnapshot $snapshot): void
    {
        $this->snapshot = $snapshot;
        $this->saves++;
    }
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Scan\ScanState;
use Pollora\Hornet\Domain\Scan\ScanStateStore;

final class InMemoryScanStateStore implements ScanStateStore
{
    /** @var list<ScanState> every state that was saved, in order */
    public array $history = [];

    public function __construct(public ScanState $state = new ScanState) {}

    public function get(): ScanState
    {
        return $this->state;
    }

    public function save(ScanState $state): void
    {
        $this->state = $state;
        $this->history[] = $state;
    }
}

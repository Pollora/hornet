<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Scan\Baseline;
use Pollora\Hornet\Domain\Scan\BaselineStore;

final class InMemoryBaselineStore implements BaselineStore
{
    public int $saves = 0;

    /**
     * @param  array<string, Baseline>  $baselines
     */
    public function __construct(public array $baselines = []) {}

    public function load(): array
    {
        return $this->baselines;
    }

    public function save(array $baselines): void
    {
        $this->baselines = $baselines;
        $this->saves++;
    }
}

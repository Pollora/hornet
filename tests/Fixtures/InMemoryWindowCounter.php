<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Journal\WindowCounter;

final class InMemoryWindowCounter implements WindowCounter
{
    /** @var array<string, int> */
    public array $counts = [];

    public function hit(string $key, int $window): int
    {
        return $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }

    public function reset(): void
    {
        $this->counts = [];
    }
}

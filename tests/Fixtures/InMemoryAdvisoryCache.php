<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Scan\AdvisoryCache;

final class InMemoryAdvisoryCache implements AdvisoryCache
{
    /** @var array<string, array<mixed>> */
    public array $stored = [];

    /** @var array<string, int> */
    public array $kept = [];

    public function get(string $key): ?array
    {
        return $this->stored[$key] ?? null;
    }

    public function put(string $key, array $value, int $seconds): void
    {
        $this->stored[$key] = $value;
        $this->kept[$key] = $seconds;
    }
}

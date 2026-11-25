<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Login\Attempts;
use Pollora\Hornet\Domain\Login\AttemptStore;

final class InMemoryAttemptStore implements AttemptStore
{
    /** @var array<string, Attempts> */
    public array $records = [];

    /** @var array<string, int> */
    public array $keptFor = [];

    public function get(string $key): Attempts
    {
        return $this->records[$key] ?? new Attempts;
    }

    public function put(string $key, Attempts $attempts, int $keepFor): void
    {
        $this->records[$key] = $attempts;
        $this->keptFor[$key] = $keepFor;
    }

    public function forget(string $key): void
    {
        unset($this->records[$key]);
    }

    public function forgetAll(): int
    {
        $locked = count(array_filter($this->records, static fn (Attempts $attempts): bool => $attempts->lockedUntil > 0));
        $this->records = [];

        return $locked;
    }
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\Audit\SetAsideStore;

final class InMemorySetAsideStore implements SetAsideStore
{
    /** @param array<string, SetAside> $asides */
    public function __construct(public array $asides = []) {}

    public function all(): array
    {
        return $this->asides;
    }

    public function put(SetAside $aside): void
    {
        $this->asides[$aside->check] = $aside;
    }

    public function remove(string $check): void
    {
        unset($this->asides[$check]);
    }
}

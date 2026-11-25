<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Infrastructure\Config\SecretSource;

final readonly class ArraySecrets implements SecretSource
{
    /**
     * @param  array<string, string>  $env
     * @param  array<string, string>  $constants
     */
    public function __construct(
        private array $env = [],
        private array $constants = [],
    ) {}

    public function env(string $name): ?string
    {
        return $this->env[$name] ?? null;
    }

    public function constant(string $name): ?string
    {
        return $this->constants[$name] ?? null;
    }
}

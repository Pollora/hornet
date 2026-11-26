<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

/**
 * Where a secret named in the settings is read from: an environment variable or a constant, never the database.
 *
 * @internal
 */
interface SecretSource
{
    public function env(string $name): ?string;

    public function constant(string $name): ?string;
}

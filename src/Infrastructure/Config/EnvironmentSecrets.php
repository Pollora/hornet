<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

/**
 * Environment variables (a Bedrock `.env`, the server) and constants (`wp-config.php`).
 *
 * @internal
 */
final class EnvironmentSecrets implements SecretSource
{
    public function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function constant(string $name): ?string
    {
        $value = defined($name) ? constant($name) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}

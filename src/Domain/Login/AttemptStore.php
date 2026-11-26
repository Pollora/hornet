<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Login;

/**
 * Where the failures of each address are kept: a hash of the address, never the address.
 *
 * @internal
 */
interface AttemptStore
{
    public function get(string $key): Attempts;

    public function put(string $key, Attempts $attempts, int $keepFor): void;

    public function forget(string $key): void;

    /**
     * Forgets every address; returns how many were locked.
     */
    public function forgetAll(): int;
}

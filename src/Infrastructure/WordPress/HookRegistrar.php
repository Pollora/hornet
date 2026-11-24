<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

/**
 * Where a module attaches its callbacks: WordPress itself, or Pollora's hook service.
 */
interface HookRegistrar
{
    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void;

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void;
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

/**
 * The hooks of WordPress, through its global functions.
 *
 * @internal
 */
final class WordPressHooks implements HookRegistrar
{
    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        add_action($hook, $callback, $priority, $acceptedArgs);
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        add_filter($hook, $callback, $priority, $acceptedArgs);
    }
}

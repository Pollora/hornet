<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Infrastructure\WordPress\HookRegistrar;

/**
 * Remembers what a module attached, so a test can read it back and call it.
 */
final class RecordingHooks implements HookRegistrar
{
    /** @var list<array{type: string, hook: string, callback: callable, priority: int}> */
    public array $registered = [];

    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->registered[] = ['type' => 'action', 'hook' => $hook, 'callback' => $callback, 'priority' => $priority];
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->registered[] = ['type' => 'filter', 'hook' => $hook, 'callback' => $callback, 'priority' => $priority];
    }

    public function callback(string $hook): callable
    {
        foreach ($this->registered as $entry) {
            if ($entry['hook'] === $hook) {
                return $entry['callback'];
            }
        }

        throw new \LogicException('Nothing is attached to '.$hook);
    }
}

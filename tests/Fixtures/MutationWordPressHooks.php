<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Infrastructure\WordPress\HookRegistrar;
use Pollora\Hornet\Infrastructure\WordPress\Module;

/**
 * Remembers how a module attached each callback, with the number of arguments it asks of WordPress: a callback that is given
 * fewer than it declares as required fails on a real site.
 */
final class MutationWordPressHooks implements HookRegistrar
{
    /** @var list<array{string, string, int, int}> type, hook, priority, accepted arguments */
    public array $table = [];

    /** @var array<string, list<callable>> */
    private array $callbacks = [];

    public static function of(Module $module): self
    {
        $hooks = new self;
        $module->register($hooks);

        return $hooks;
    }

    public function addAction(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->table[] = ['action', $hook, $priority, $acceptedArgs];
        $this->callbacks[$hook][] = $callback;
    }

    public function addFilter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
    {
        $this->table[] = ['filter', $hook, $priority, $acceptedArgs];
        $this->callbacks[$hook][] = $callback;
    }

    /**
     * Runs a hook as WordPress would: every callback, with as many arguments as it asked for.
     */
    public function fire(string $hook, mixed ...$arguments): mixed
    {
        $result = $arguments[0] ?? null;
        $index = 0;

        foreach ($this->table as [$type, $name, , $accepted]) {
            if ($name !== $hook) {
                continue;
            }

            $returned = $this->callbacks[$hook][$index++](...array_slice($arguments, 0, $accepted));
            $result = $returned;

            // A filter hands what it returns to the next one
            if ($type === 'filter') {
                $arguments[0] = $returned;
            }
        }

        return $result;
    }
}

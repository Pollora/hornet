<?php

declare(strict_types=1);

use Pollora\Hornet\Infrastructure\Pollora\PolloraHooks;

it('hands the hooks to the Pollora hook services', function (): void {
    $action = new class
    {
        /** @var list<array<mixed>> */
        public array $calls = [];

        public function add(string $hook, callable $callback, int $priority, int $args): void
        {
            $this->calls[] = [$hook, $priority, $args];
        }
    };
    $filter = clone $action;

    $hooks = new PolloraHooks($action, $filter);
    $hooks->addAction('init', fn () => null, 5, 2);
    $hooks->addFilter('site_status_tests', fn (array $t) => $t);

    expect($action->calls)->toBe([['init', 5, 2]])->and($filter->calls)->toBe([['site_status_tests', 10, 1]]);
});

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

/**
 * The part of WP_REST_Request that Hornet uses.
 */
final class FakeRestRequest
{
    /** @var array<string, mixed> */
    private array $params = [];

    public function __construct(public readonly string $method = '', public readonly string $route = '') {}

    public function set_param(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }
}

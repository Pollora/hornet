<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

/**
 * The part of WP_REST_Response that Hornet uses, for the unit suite, which loads no WordPress.
 */
final class FakeRestResponse
{
    public function __construct(private mixed $data = null) {}

    public function get_data(): mixed
    {
        return $this->data;
    }

    public function set_data(mixed $data): void
    {
        $this->data = $data;
    }
}

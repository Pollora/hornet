<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

/**
 * The part of WP_Error that Hornet uses, for the unit suite, which loads no WordPress.
 */
final class FakeWpError
{
    public function __construct(
        private readonly string $code = '',
        private readonly string $message = '',
    ) {}

    /** @var list<array{string, string}> */
    public array $added = [];

    public function add(string $code, string $message): void
    {
        $this->added[] = [$code, $message];
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }
}

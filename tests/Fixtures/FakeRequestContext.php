<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Application\Journal\RequestContext;
use Pollora\Hornet\Domain\Journal\Source;

final class FakeRequestContext implements RequestContext
{
    public function __construct(
        public Source $source = Source::Admin,
        public int $user = 7,
        public string $login = 'olivier',
        public string $address = '203.0.113.77',
    ) {}

    public function source(): Source
    {
        return $this->source;
    }

    public function userId(): int
    {
        return $this->user;
    }

    public function userLogin(): string
    {
        return $this->login;
    }

    public function ip(): string
    {
        return $this->address;
    }
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

/**
 * The part of WP_User that Hornet reads, for the unit suite, which loads no WordPress.
 */
final class FakeWpUser
{
    public int $ID = 0;

    public string $user_login = '';

    public string $user_registered = '';

    /** @var list<string> */
    public array $roles = [];

    public static function named(int $id, string $login): self
    {
        $user = new self;
        $user->ID = $id;
        $user->user_login = $login;

        return $user;
    }

    public function exists(): bool
    {
        return $this->ID > 0;
    }
}

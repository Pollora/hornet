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
}

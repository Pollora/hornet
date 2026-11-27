<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Unit\Malformed;

use AllowDynamicProperties;

/**
 * Gives the unit suite, which has no WordPress, a `wpdb` for Mockery to extend: a test mocks what it is asked.
 */
#[AllowDynamicProperties]
class WpdbStandIn
{
    public string $prefix = 'wp_';

    public string $posts = 'wp_posts';

    public string $last_error = '';
}

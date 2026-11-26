<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Domain\Audit\LaravelApplication;

/**
 * Whether WordPress runs inside a Laravel application. Only the service provider of the package says so, and only Laravel runs it:
 * on a classic WordPress or on Bedrock nothing is ever set.
 */
final class HostApplication
{
    private static ?LaravelApplication $laravel = null;

    public static function set(?LaravelApplication $application): void
    {
        self::$laravel = $application;
    }

    public static function laravel(): ?LaravelApplication
    {
        return self::$laravel;
    }
}

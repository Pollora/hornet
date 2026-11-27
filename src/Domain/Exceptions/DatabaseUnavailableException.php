<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Exceptions;

use RuntimeException;

/**
 * There is no database connection of WordPress to ask: nothing is said to be empty on that ground.
 *
 * @internal
 */
final class DatabaseUnavailableException extends RuntimeException
{
    public static function noConnection(): self
    {
        return new self('The database connection of WordPress is not there.');
    }
}

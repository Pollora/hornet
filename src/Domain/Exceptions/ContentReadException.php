<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Exceptions;

use RuntimeException;

/**
 * The database refused a query. An empty answer would look like an empty table, and so like a clean one: this says it is not.
 * The message holds neither the query nor what the database said.
 *
 * @internal
 */
final class ContentReadException extends RuntimeException
{
    public static function table(string $source): self
    {
        return new self(sprintf('The "%s" table could not be read.', $source));
    }
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Exceptions;

use InvalidArgumentException;

/**
 * The rules for a web server could not be written for what was asked.
 */
final class ServerRulesException extends InvalidArgumentException
{
    public static function unknownServer(string $server): self
    {
        return new self(sprintf('Unknown server "%s": use nginx or apache.', $server));
    }

    public static function badPath(string $path): self
    {
        return new self(sprintf('"%s" is not a path of the uploads folder: it is a URL path such as /wp-content/uploads or /app/uploads, with letters, digits, dots, dashes and underscores.', $path));
    }
}

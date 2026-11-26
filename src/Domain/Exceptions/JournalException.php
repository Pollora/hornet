<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Exceptions;

use RuntimeException;

/**
 * An event could not be written to the journal. The recorder counts it and goes on: a journal never stops a request.
 *
 * @internal
 */
final class JournalException extends RuntimeException
{
    public static function notWritten(): self
    {
        return new self('The event could not be written.');
    }
}

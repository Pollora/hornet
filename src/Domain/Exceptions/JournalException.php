<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Exceptions;

use RuntimeException;

/**
 * The journal could not be written or read. The recorder counts what it could not write and goes on: a journal never stops a request.
 * A read that fails is said, since an empty answer would pass for a journal that holds nothing.
 *
 * @internal
 */
final class JournalException extends RuntimeException
{
    public static function notWritten(): self
    {
        return new self('The event could not be written.');
    }

    public static function notRead(): self
    {
        return new self('The journal could not be read.');
    }
}

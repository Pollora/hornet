<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Exceptions;

use InvalidArgumentException;

/**
 * A setting names a check that does not exist: refused, so that a typo does not leave a check running that was meant to be set aside.
 */
final class UnknownCheckException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $id, array $known): self
    {
        return new self(sprintf('No Hornet check is named "%s". Known checks: %s.', $id, implode(', ', $known)));
    }
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Exceptions;

use LogicException;

/**
 * The hook service of the host cannot attach anything: said, because a protection that was meant to be on must not be silently off.
 *
 * @internal
 */
final class HookServiceException extends LogicException
{
    public static function notAnObject(): self
    {
        return new self('The hook services of Pollora are not objects: Hornet cannot attach itself.');
    }

    public static function cannotAdd(string $service): self
    {
        return new self(sprintf('The hook service %s has no add() method.', $service));
    }
}

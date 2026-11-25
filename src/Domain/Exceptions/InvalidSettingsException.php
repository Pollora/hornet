<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Exceptions;

use InvalidArgumentException;

/**
 * A setting that does not mean anything: a typo in a key is refused, never ignored, because a protection that was meant to be on must not be silently off.
 */
final class InvalidSettingsException extends InvalidArgumentException
{
    public static function unknownKey(string $key, string $where): self
    {
        return new self(sprintf('Unknown Hornet setting "%s"%s.', $key, $where === '' ? '' : ' in '.$where));
    }

    public static function invalid(string $key, string $expected): self
    {
        return new self(sprintf('The Hornet setting "%s" must be %s.', $key, $expected));
    }

    /**
     * A problem with one notification channel. The message never holds a URL, a token or a key.
     */
    public static function channel(string $channel, string $problem): self
    {
        return new self(sprintf('Hornet notification channel "%s": %s', $channel, $problem));
    }
}

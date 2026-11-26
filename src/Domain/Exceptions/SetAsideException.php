<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Exceptions;

use Pollora\Hornet\Domain\I18n\Message;
use RuntimeException;

/**
 * A finding could not be set aside, or brought back.
 */
final class SetAsideException extends RuntimeException
{
    public const string UNKNOWN = 'unknown';

    public const string REASON = 'reason';

    public const string LOCKED = 'locked';

    public const string NOT_OPEN = 'not_open';

    private function __construct(public readonly Message $say, public readonly string $kind)
    {
        parent::__construct($say->english());
    }

    public static function unknownCheck(string $check): self
    {
        return new self(Message::of('There is no check "%s".', $check), self::UNKNOWN);
    }

    public static function reasonNeeded(int $min): self
    {
        return new self(Message::of('Say why, in at least %d characters: a finding that is set aside without a reason is a finding that is forgotten.', $min), self::REASON);
    }

    public static function lockedByFile(string $check): self
    {
        return new self(Message::of('"%s" is set aside in the settings file, which wins over the screen: change it there.', $check), self::LOCKED);
    }

    public static function notOpen(string $check): self
    {
        return new self(Message::of('"%s" has nothing open to set aside.', $check), self::NOT_OPEN);
    }
}

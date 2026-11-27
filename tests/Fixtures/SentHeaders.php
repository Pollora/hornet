<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

/**
 * The headers the package asked PHP to send, and whether output had already begun.
 */
final class SentHeaders
{
    /** @var list<array{string, bool}> the header line, and whether it replaces one of the same name */
    public static array $sent = [];

    public static bool $alreadySent = false;

    public static function reset(): void
    {
        self::$sent = [];
        self::$alreadySent = false;
    }
}

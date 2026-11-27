<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain;

/**
 * A value of unknown shape (a request, a row, an option, an answer from outside) read as text or as a number.
 *
 * A scalar is cast as PHP casts it. Anything else (an array, an object, nothing) is not read: the default stands for it.
 *
 * @internal
 */
final class Scalar
{
    public static function string(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return is_scalar($value) ? (int) $value : $default;
    }
}

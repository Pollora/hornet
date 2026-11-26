<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Http;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;

/**
 * What may go in the value of a header. A line break in a value is how a second header is injected.
 *
 * @internal
 */
final class HeaderText
{
    private const int LONGEST = 4096;

    /**
     * @throws InvalidSettingsException
     */
    public static function check(string $setting, string $value): string
    {
        // Judged as it was written: trim() would strip a trailing NUL byte and hide it
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || trim($value, ' ') === '' || strlen($value) > self::LONGEST) {
            throw InvalidSettingsException::invalid($setting, 'a single line of text, 4096 characters at most, with no control character');
        }

        return trim($value, ' ');
    }
}

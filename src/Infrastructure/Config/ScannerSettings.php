<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Scan\CoreIntegrity;

/**
 * The `scanner` settings. The scan runs when someone asks; there is nothing to turn on.
 */
final readonly class ScannerSettings
{
    private const array KEYS = ['allow_modified'];

    /**
     * @param  list<string>  $allowModified  core files that are meant to differ from WordPress.org
     */
    private function __construct(public array $allowModified) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @throws InvalidSettingsException
     */
    public static function fromArray(mixed $value): self
    {
        if (! is_array($value)) {
            throw InvalidSettingsException::invalid('scanner', 'an array');
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw InvalidSettingsException::unknownKey((string) $key, 'scanner');
            }
        }

        $paths = $value['allow_modified'] ?? [];
        $expected = "a list of core file paths, relative to the WordPress root (for example 'wp-includes/l10n.php')";

        if (! is_array($paths) || ! array_is_list($paths)) {
            throw InvalidSettingsException::invalid('scanner.allow_modified', $expected);
        }

        $allowed = [];

        foreach ($paths as $path) {
            $allowed[] = is_string($path) && CoreIntegrity::isSafe($path) ? $path : throw InvalidSettingsException::invalid('scanner.allow_modified', $expected);
        }

        return new self($allowed);
    }
}

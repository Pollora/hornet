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
    private const array KEYS = ['allow_modified', 'ignore_paths', 'schedule'];

    private const array SCHEDULES = ['off', 'hourly', 'twicedaily', 'daily'];

    /**
     * @param  list<string>  $allowModified  core files that are meant to differ from WordPress.org
     * @param  list<string>  $ignorePaths  files and folders of the content folder that the suspicious-files scan leaves alone
     * @param  string  $schedule  off, hourly, twicedaily or daily
     */
    private function __construct(
        public array $allowModified,
        public array $ignorePaths,
        public string $schedule,
    ) {}

    public static function none(): self
    {
        return new self([], [], 'off');
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

        $allowed = self::paths($value['allow_modified'] ?? [], 'allow_modified', "a list of core file paths, relative to the WordPress root (for example 'wp-includes/l10n.php')");
        $ignored = self::paths($value['ignore_paths'] ?? [], 'ignore_paths', "a list of files or folders, relative to the content folder (for example 'themes/mine/vendor')");
        $schedule = $value['schedule'] ?? 'off';

        if (! is_string($schedule) || ! in_array($schedule, self::SCHEDULES, true)) {
            throw InvalidSettingsException::invalid('scanner.schedule', implode(', ', self::SCHEDULES));
        }

        return new self($allowed, $ignored, $schedule);
    }

    /**
     * @return list<string>
     */
    private static function paths(mixed $paths, string $key, string $expected): array
    {
        if (! is_array($paths) || ! array_is_list($paths)) {
            throw InvalidSettingsException::invalid('scanner.'.$key, $expected);
        }

        $clean = [];

        foreach ($paths as $path) {
            $path = is_string($path) ? rtrim($path, '/') : null;
            $clean[] = $path !== null && CoreIntegrity::isSafe($path) ? $path : throw InvalidSettingsException::invalid('scanner.'.$key, $expected);
        }

        return $clean;
    }
}

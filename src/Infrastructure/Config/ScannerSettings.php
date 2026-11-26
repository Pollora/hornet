<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Scan\CoreIntegrity;

/**
 * The `scanner` settings. The scan runs when someone asks; there is nothing to turn on.
 *
 * @internal
 */
final readonly class ScannerSettings
{
    private const array KEYS = ['allow_modified', 'ignore_paths', 'schedule', 'own_themes', 'probes', 'probe_uploads', 'ignore_content'];

    private const array SCHEDULES = ['off', 'hourly', 'twicedaily', 'daily'];

    /**
     * @param  list<string>  $allowModified  core files that are meant to differ from WordPress.org
     * @param  list<string>  $ignorePaths  files and folders of the content folder that the suspicious-files scan leaves alone
     * @param  string  $schedule  off, hourly, twicedaily or daily
     * @param  list<string>  $ownThemes  slugs of the themes written for this site: their files change with every deployment, and are not compared with a first look
     * @param  bool  $probes  whether the scan asks the site itself for the files it should not give away
     * @param  bool  $probeUploads  whether the scan puts a file in the uploads for a moment, to ask whether the server runs PHP there
     * @param  list<string>  $ignoreContent  entries of the database that the database-scripts scan leaves alone (`post:12`, `option:widget_text`)
     */
    private function __construct(
        public array $allowModified,
        public array $ignorePaths,
        public string $schedule,
        public array $ownThemes,
        public bool $probes,
        public bool $probeUploads,
        public array $ignoreContent = [],
    ) {}

    public static function none(): self
    {
        return new self([], [], 'off', [], true, false);
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

        $own = self::slugs($value['own_themes'] ?? []);

        return new self($allowed, $ignored, $schedule, $own, self::flag($value, 'probes', true), self::flag($value, 'probe_uploads', false), self::entries($value['ignore_content'] ?? []));
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

    /**
     * @return list<string>
     */
    private static function slugs(mixed $slugs): array
    {
        $expected = "a list of theme folder names (for example 'my-theme')";

        if (! is_array($slugs) || ! array_is_list($slugs)) {
            throw InvalidSettingsException::invalid('scanner.own_themes', $expected);
        }

        $clean = [];

        foreach ($slugs as $slug) {
            $clean[] = is_string($slug) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $slug) === 1 ? $slug : throw InvalidSettingsException::invalid('scanner.own_themes', $expected);
        }

        return $clean;
    }

    /**
     * @return list<string>
     */
    private static function entries(mixed $entries): array
    {
        $expected = "a list of database entries (for example 'post:12', 'comment:3', 'user:5' or 'option:widget_text')";

        if (! is_array($entries) || ! array_is_list($entries)) {
            throw InvalidSettingsException::invalid('scanner.ignore_content', $expected);
        }

        $clean = [];

        foreach ($entries as $entry) {
            $clean[] = is_string($entry) && preg_match('/^(?:(?:post|comment|user):[1-9]\d*|option:[A-Za-z0-9_.:-]{1,64})$/D', $entry) === 1 ? $entry : throw InvalidSettingsException::invalid('scanner.ignore_content', $expected);
        }

        return $clean;
    }

    /**
     * @param  array<mixed>  $value
     */
    private static function flag(array $value, string $key, bool $default): bool
    {
        $flag = $value[$key] ?? $default;

        return is_bool($flag) ? $flag : throw InvalidSettingsException::invalid('scanner.'.$key, 'true or false');
    }
}

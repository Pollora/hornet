<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Pollora;

use Composer\InstalledVersions;
use Pollora\Hornet\Domain\Scan\CoreIntegrity;

/**
 * The core files that a Pollora project patches on purpose (`__()` is renamed in wp-includes/l10n.php so that Laravel can declare it).
 *
 * The project's `patches.lock.json` lists the patches of the core package by the SHA-256 of the file; the patch itself is
 * looked for in the framework and in the project's `patches` folder, and taken only when its hash is the one in the lock.
 * A patch that cannot be found is not guessed: the file shows as changed, and the owner lists it in `scanner.allow_modified`.
 *
 * @internal
 */
final class CorePatches
{
    /** The packages that carry the WordPress core: Pollora and Bedrock. */
    private const array PACKAGES = ['johnpbloch/wordpress-core', 'roots/wordpress', 'roots/wordpress-no-content', 'roots/wordpress-full'];

    /**
     * The files that the project patches, found from the project the package is installed in.
     *
     * @return list<string>
     */
    public static function files(): array
    {
        if (! class_exists(InstalledVersions::class)) {
            return [];
        }

        $root = InstalledVersions::getRootPackage()['install_path'] ?? null;

        if (! is_string($root)) {
            return [];
        }

        $directories = [$root.'/patches'];

        if (InstalledVersions::isInstalled('pollora/framework')) {
            $directories[] = (InstalledVersions::getInstallPath('pollora/framework') ?? '').'/patches';
        }

        return self::in($root, $directories);
    }

    /**
     * @param  string  $root  the folder of the project, where patches.lock.json is
     * @param  list<string>  $directories  where the patch files may be
     * @return list<string>
     */
    public static function in(string $root, array $directories): array
    {
        if (! is_file($root.'/patches.lock.json')) {
            return [];
        }

        $files = [];

        foreach (self::patches($root.'/patches.lock.json') as $patch) {
            foreach (self::candidates($directories, $patch['sha256']) as $candidate) {
                array_push($files, ...self::touched((string) file_get_contents($candidate), $patch['depth']));
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @return list<array{sha256: string, depth: int}>
     */
    private static function patches(string $lock): array
    {
        $data = json_decode((string) file_get_contents($lock), true);
        $patches = [];

        foreach (self::PACKAGES as $package) {
            foreach (is_array($data) && is_array($data['patches'] ?? null) && is_array($data['patches'][$package] ?? null) ? $data['patches'][$package] : [] as $patch) {
                if (is_array($patch) && is_string($patch['sha256'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $patch['sha256']) === 1) {
                    $patches[] = ['sha256' => $patch['sha256'], 'depth' => is_int($patch['depth'] ?? null) ? $patch['depth'] : 1];
                }
            }
        }

        return $patches;
    }

    /**
     * @param  list<string>  $directories
     * @return list<string>
     */
    private static function candidates(array $directories, string $sha256): array
    {
        $found = [];

        foreach ($directories as $directory) {
            foreach (glob($directory.'/*.patch') ?: [] as $file) {
                if (is_file($file) && hash_equals($sha256, hash_file('sha256', $file) ?: '')) {
                    $found[] = $file;
                }
            }
        }

        return $found;
    }

    /**
     * The files a patch changes: the `+++ b/path` lines, with `$depth` leading folders removed.
     *
     * @return list<string>
     */
    public static function touched(string $patch, int $depth = 1): array
    {
        $files = [];

        foreach (preg_split('/\R/', $patch) ?: [] as $line) {
            if (! str_starts_with($line, '+++ ')) {
                continue;
            }

            // The path ends at the first tab or space: a date may follow
            $path = preg_split('/[\t ]/', substr($line, 4), 2)[0] ?? '';

            // A patch that deletes a file points at /dev/null
            if ($path === '/dev/null') {
                continue;
            }

            $path = implode('/', array_slice(explode('/', $path), $depth));

            if ($path !== '' && CoreIntegrity::isSafe($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }
}

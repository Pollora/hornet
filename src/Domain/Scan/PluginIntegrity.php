<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * Compares the files of one plugin with a list: the one WordPress.org publishes, or the baseline of the first look.
 *
 * It reads; it never repairs. A file that is not on the list is reported only when it is a script: a plugin
 * leaves caches, logs and uploads in its folder, and a report full of those would hide the one that matters.
 *
 * @internal
 */
final readonly class PluginIntegrity
{
    /** Folders of tools, not of the plugin: a build leaves them behind, and they hold thousands of files. */
    private const array SKIPPED = ['node_modules', '.git'];

    /**
     * @param  FileSystem  $plugins  the plugins directory
     */
    public function __construct(private FileSystem $plugins) {}

    /**
     * @param  array<string, list<string>>  $expected  the SHA-256 a file may have, by path inside the plugin
     */
    public function inspect(string $slug, array $expected): ?PluginVerdict
    {
        $files = $this->plugins->files($slug, true);

        if ($files === null) {
            return null;
        }

        $prefix = $slug.'/';
        $modified = [];
        $missing = [];
        $unreadable = [];
        $code = [];
        $checked = 0;

        foreach ($expected as $path => $accepted) {
            $path = (string) $path;

            if (! CoreIntegrity::isSafe($path)) {
                continue;
            }

            $checked++;
            $actual = $this->plugins->sha256($prefix.$path);

            if ($actual === null) {
                if ($this->plugins->exists($prefix.$path)) {
                    $unreadable[] = $path;
                } else {
                    $missing[] = $path;
                }

                continue;
            }

            if (! $this->accepts($accepted, $actual)) {
                $modified[] = $path;
            }
        }

        foreach ($files as $file) {
            $path = substr($file, strlen($prefix));

            if (! isset($expected[$path]) && ! $this->skipped($path) && CoreIntegrity::isScript($path)) {
                $code[] = $path;
            }
        }

        return new PluginVerdict($slug, $checked, $modified, $missing, $unreadable, $code);
    }

    /**
     * The files of a plugin that a baseline is made of: every file, except the folders of tools.
     *
     * @return array<string, string>|null SHA-256 by path; null when the folder cannot be read
     */
    public function hashes(string $slug): ?array
    {
        $files = $this->plugins->files($slug, true);

        if ($files === null) {
            return null;
        }

        $hashes = [];

        foreach ($files as $file) {
            $path = substr($file, strlen($slug) + 1);
            $hash = $this->skipped($path) ? null : $this->plugins->sha256($file);

            if ($hash !== null) {
                $hashes[$path] = $hash;
            }
        }

        return $hashes;
    }

    /**
     * @param  list<string>  $accepted
     */
    private function accepts(array $accepted, string $actual): bool
    {
        foreach ($accepted as $hash) {
            if (hash_equals($hash, $actual)) {
                return true;
            }
        }

        return false;
    }

    private function skipped(string $path): bool
    {
        return array_intersect(explode('/', $path), self::SKIPPED) !== [];
    }
}

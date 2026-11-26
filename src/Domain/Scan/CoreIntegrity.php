<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * Compares the core files of the install with the official list. It reads; it never repairs a file.
 *
 * Three things are looked at, the way `wp core verify-checksums` does it, and one more: a file that differs, a file that is
 * gone, and a file that WordPress does not ship in a folder it owns (wp-admin, wp-includes, the root: a backdoor lands there).
 * Everything under wp-content is the site's own, and is left to the other scans.
 *
 * @internal
 */
final readonly class CoreIntegrity
{
    /** What a host or a tool leaves next to the core, and that is not a sign of anything. */
    private const array NOISE = ['error_log', '.ds_store'];

    /** The extensions a server runs as PHP. `D`: the end of the name is the end of the name, not a name followed by a line break. */
    private const string SCRIPT = '/\.(?:php\d?|phtml|pht|phar|inc)$/iD';

    public function __construct(private FileSystem $files) {}

    /**
     * @param  array<string, string>  $official  the MD5 of each official file by path
     * @param  list<string>  $allowed  files that are meant to differ (a patch the project applies)
     * @param  array<string, string>  $pinned  the MD5 those files had when they were first seen
     */
    public function inspect(array $official, array $allowed, array $pinned): ?CoreVerdict
    {
        $inRoot = $this->files->files('', false);
        $inAdmin = $this->files->files('wp-admin', true);
        $inIncludes = $this->files->files('wp-includes', true);

        if ($inRoot === null || $inAdmin === null || $inIncludes === null) {
            return null;
        }

        $official = $this->core($official);
        $modified = [];
        $missing = [];
        $unreadable = [];
        $changed = [];
        $patched = [];

        foreach ($official as $path => $expected) {
            $path = (string) $path;
            $actual = $this->files->md5($path);

            if ($actual === null) {
                if ($this->files->exists($path)) {
                    $unreadable[] = $path;
                } else {
                    $missing[] = $path;
                }

                continue;
            }

            if (hash_equals($expected, $actual)) {
                continue;
            }

            if (! in_array($path, $allowed, true)) {
                $modified[] = $path;

                continue;
            }

            $patched[$path] = $actual;

            if (isset($pinned[$path]) && ! hash_equals($pinned[$path], $actual)) {
                $changed[] = $path;
            }
        }

        $unexpected = [];

        foreach ([...$inRoot, ...$inAdmin, ...$inIncludes] as $path) {
            if (isset($official[$path]) || in_array(strtolower(basename($path)), self::NOISE, true)) {
                continue;
            }

            $isRoot = ! str_contains($path, '/');

            // The root holds the site's own files: only a script there is suspicious, and wp-config.php is the site's
            if ($isRoot && (! self::isScript($path) || $path === 'wp-config.php')) {
                continue;
            }

            $unexpected[] = $path;
        }

        return new CoreVerdict(count($official), $modified, $missing, $unreadable, $unexpected, $changed, $patched);
    }

    /**
     * The official list, without what is not the core's: the site's content, and any path that is not a plain relative one
     * (the list comes from the network, so a path is never used before it is checked).
     *
     * @param  array<string, string>  $official
     * @return array<string, string>
     */
    private function core(array $official): array
    {
        $core = [];

        foreach ($official as $path => $md5) {
            $path = (string) $path;

            if (str_starts_with($path, 'wp-content/') || ! self::isSafe($path) || preg_match('/^[0-9a-f]{32}$/', $md5) !== 1) {
                continue;
            }

            $core[$path] = $md5;
        }

        return $core;
    }

    /**
     * Whether the name is one that a server runs as PHP.
     */
    public static function isScript(string $path): bool
    {
        return preg_match(self::SCRIPT, $path) === 1;
    }

    /**
     * A path inside the install: relative, with no `..`, no empty part, no control character.
     */
    public static function isSafe(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $part) {
            if (in_array($part, ['', '.', '..'], true)) {
                return false;
            }
        }

        return true;
    }
}

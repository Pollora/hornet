<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Scan;

use FilesystemIterator;
use Pollora\Hornet\Domain\Scan\CoreIntegrity;
use Pollora\Hornet\Domain\Scan\FileSystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * The disk, read through PHP. It never writes, and it never leaves the root it was given.
 */
final readonly class NativeFileSystem implements FileSystem
{
    private function __construct(private string $root) {}

    /**
     * @param  string  $path  the folder that holds wp-load.php (ABSPATH); a symbolic link (Bedrock, Deployer) is resolved
     * @return self|null null when the folder cannot be read from here
     */
    public static function at(string $path): ?self
    {
        $root = realpath($path);

        return $root !== false && is_dir($root) && is_readable($root) ? new self(rtrim($root, '/\\')) : null;
    }

    public function files(string $directory, bool $recursive): ?array
    {
        $path = $this->resolve($directory);

        if ($path === null || ! is_dir($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $entries = $recursive
                ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD)
                : new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);

            $files = [];

            foreach ($entries as $entry) {
                if ($entry instanceof SplFileInfo && $entry->isFile()) {
                    $files[] = str_replace('\\', '/', substr($entry->getPathname(), strlen($this->root) + 1));
                }
            }
        } catch (UnexpectedValueException) {
            return null;
        }

        sort($files);

        return $files;
    }

    public function exists(string $path): bool
    {
        $file = $this->resolve($path);

        return $file !== null && file_exists($file);
    }

    public function md5(string $path): ?string
    {
        $file = $this->resolve($path);

        if ($file === null || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $hash = md5_file($file);

        return $hash === false ? null : $hash;
    }

    public function sha256(string $path): ?string
    {
        $file = $this->resolve($path);

        if ($file === null || ! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $hash = hash_file('sha256', $file);

        return $hash === false ? null : $hash;
    }

    /**
     * @return string|null null for a path that is not a plain relative one: nothing outside the root is ever read
     */
    private function resolve(string $path): ?string
    {
        if ($path === '') {
            return $this->root;
        }

        return CoreIntegrity::isSafe($path) ? $this->root.'/'.$path : null;
    }
}

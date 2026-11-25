<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Scan\FileSystem;

/**
 * A WordPress install in an array: path => contents. A path set to null exists and cannot be read.
 */
final class ArrayFileSystem implements FileSystem
{
    /**
     * @param  array<string, string|null>  $files
     * @param  list<string>  $unlistable  directories that cannot be listed
     */
    public function __construct(
        public array $files = [],
        public array $unlistable = [],
    ) {}

    public function files(string $directory, bool $recursive): ?array
    {
        if (in_array($directory, $this->unlistable, true)) {
            return null;
        }

        $prefix = $directory === '' ? '' : $directory.'/';
        $found = [];

        foreach (array_keys($this->files) as $path) {
            $path = (string) $path;

            if (! str_starts_with($path, $prefix)) {
                continue;
            }

            if ($recursive || ! str_contains(substr($path, strlen($prefix)), '/')) {
                $found[] = $path;
            }
        }

        sort($found);

        return $found;
    }

    public function exists(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function md5(string $path): ?string
    {
        $contents = $this->files[$path] ?? null;

        return $contents === null ? null : md5($contents);
    }

    /**
     * The official list of this install: the MD5 of each file as it is now. WordPress.org does not list wp-config.php (it ships a sample).
     *
     * @return array<string, string>
     */
    public function official(): array
    {
        $list = [];

        foreach ($this->files as $path => $contents) {
            if ($contents !== null && $path !== 'wp-config.php') {
                $list[$path] = md5($contents);
            }
        }

        return $list;
    }
}

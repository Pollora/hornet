<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * The files of the WordPress install, read and never changed. Paths are relative to the WordPress root and use `/`.
 */
interface FileSystem
{
    /**
     * The regular files of a directory (`''` is the root of WordPress), the ones of its sub-directories too when `$recursive` is set.
     * A symbolic link is not followed: a link to a directory is never walked.
     *
     * @return list<string>|null null when the directory cannot be read (open_basedir, permissions)
     */
    public function files(string $directory, bool $recursive): ?array;

    public function exists(string $path): bool;

    /** The MD5 of a file, null when it is missing or cannot be read. */
    public function md5(string $path): ?string;

    /** The start of a file: at most `$maxBytes` bytes. Null when it is missing or cannot be read. */
    public function read(string $path, int $maxBytes): ?string;

    /** The size of a file in bytes, null when it is missing or cannot be read. */
    public function size(string $path): ?int;

    /** The SHA-256 of a file, null when it is missing or cannot be read. */
    public function sha256(string $path): ?string;
}

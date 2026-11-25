<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Scan\FileSystem;

/**
 * A disk that is slow: every read of a file takes a given time on the clock of the test.
 */
final readonly class TickingFileSystem implements FileSystem
{
    public function __construct(
        private FileSystem $inner,
        private FakeClock $clock,
        private string $perRead,
    ) {}

    public function files(string $directory, bool $recursive): ?array
    {
        return $this->inner->files($directory, $recursive);
    }

    public function exists(string $path): bool
    {
        return $this->inner->exists($path);
    }

    public function md5(string $path): ?string
    {
        return $this->inner->md5($path);
    }

    public function read(string $path, int $maxBytes): ?string
    {
        $this->clock->advance($this->perRead);

        return $this->inner->read($path, $maxBytes);
    }

    public function size(string $path): ?int
    {
        return $this->inner->size($path);
    }

    public function sha256(string $path): ?string
    {
        return $this->inner->sha256($path);
    }
}

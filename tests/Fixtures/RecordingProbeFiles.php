<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Scan\ProbeFiles;

final class RecordingProbeFiles implements ProbeFiles
{
    /** @var array<string, string> what is on the disk now */
    public array $present = [];

    /** @var list<string> */
    public array $planted = [];

    /** @var array<string, string> what was written, kept after the file is removed */
    public array $contents = [];

    /** @var list<string> */
    public array $removed = [];

    public function __construct(private readonly bool $writable = true) {}

    public function plant(string $name, string $contents): bool
    {
        if (! $this->writable) {
            return false;
        }

        $this->present[$name] = $contents;
        $this->contents[$name] = $contents;
        $this->planted[] = $name;

        return true;
    }

    public function remove(string $name): void
    {
        unset($this->present[$name]);
        $this->removed[] = $name;
    }
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * Where the scan may put, for a moment, a file of its own in the uploads folder, to ask the server whether it would run it.
 * It is the one place where the scan writes, and it only ever removes what it wrote.
 *
 * @internal
 */
interface ProbeFiles
{
    /**
     * Writes the file, after sweeping the ones that an earlier run left behind (it died before it could remove them).
     *
     * @return bool false when it could not be written (the folder is read-only)
     */
    public function plant(string $name, string $contents): bool;

    public function remove(string $name): void;
}

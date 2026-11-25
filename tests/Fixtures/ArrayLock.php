<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Application\Notification\RunLock;

final class ArrayLock implements RunLock
{
    public bool $held = false;

    public int $released = 0;

    public function acquire(): bool
    {
        if ($this->held) {
            return false;
        }

        return $this->held = true;
    }

    public function release(): void
    {
        $this->held = false;
        $this->released++;
    }
}

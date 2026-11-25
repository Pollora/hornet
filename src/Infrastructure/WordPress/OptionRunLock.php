<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Application\Notification\RunLock;

/**
 * A lock made of an option: `add_option()` inserts a row or fails, and two processes cannot both succeed.
 * A lock older than ten minutes is the leftover of a run that died, and is taken over.
 */
final class OptionRunLock implements RunLock
{
    private const string OPTION = 'hornet_notify_lock';

    private const int STALE_AFTER = 600;

    public function acquire(): bool
    {
        if (add_option(self::OPTION, time(), '', false)) {
            return true;
        }

        $since = get_option(self::OPTION);

        if (is_int($since) && $since < time() - self::STALE_AFTER) {
            delete_option(self::OPTION);

            return add_option(self::OPTION, time(), '', false);
        }

        return false;
    }

    public function release(): void
    {
        delete_option(self::OPTION);
    }
}

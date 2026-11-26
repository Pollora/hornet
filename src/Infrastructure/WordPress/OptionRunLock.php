<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Application\Notification\RunLock;

/**
 * A lock made of an option: `add_option()` inserts a row or fails, and two processes cannot both succeed.
 * A lock older than ten minutes is the leftover of a run that died, and is taken over.
 *
 * @internal
 */
final readonly class OptionRunLock implements RunLock
{
    /**
     * @param  string  $option  the option that holds the lock: each job has its own
     */
    public function __construct(private string $option = 'hornet_notify_lock') {}

    private const int STALE_AFTER = 600;

    public function acquire(): bool
    {
        if (add_option($this->option, time(), '', false)) {
            return true;
        }

        $since = get_option($this->option);

        if (is_int($since) && $since < time() - self::STALE_AFTER) {
            delete_option($this->option);

            return add_option($this->option, time(), '', false);
        }

        return false;
    }

    public function release(): void
    {
        delete_option($this->option);
    }
}

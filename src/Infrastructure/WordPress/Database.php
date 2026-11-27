<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Domain\Exceptions\DatabaseUnavailableException;
use wpdb;

/**
 * The `$wpdb` of WordPress, checked: a drop-in (`db.php`) extends the class of core.
 *
 * @internal
 */
final class Database
{
    /**
     * @throws DatabaseUnavailableException
     */
    public static function connection(): wpdb
    {
        $database = $GLOBALS['wpdb'] ?? null;

        return $database instanceof wpdb ? $database : throw DatabaseUnavailableException::noConnection();
    }
}

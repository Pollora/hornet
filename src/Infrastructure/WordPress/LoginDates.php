<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

/**
 * When each administrator last logged in, which WordPress does not keep, and since when that is noted. Nothing else is kept:
 * one date on the account, for the accounts that can manage the site.
 */
final class LoginDates
{
    public const string META = 'hornet_last_login';

    public const string SINCE = 'hornet_logins_noted_since';

    public function start(): void
    {
        if (! get_site_option(self::SINCE)) {
            update_site_option(self::SINCE, time());
        }
    }

    public function note(int $user): void
    {
        $this->start();
        update_user_meta($user, self::META, time());
    }

    public function since(): ?int
    {
        $since = get_site_option(self::SINCE);

        return is_numeric($since) && (int) $since > 0 ? (int) $since : null;
    }

    public function last(int $user): ?int
    {
        $last = get_user_meta($user, self::META, true);

        return is_numeric($last) && (int) $last > 0 ? (int) $last : null;
    }

    public function forget(int $user): bool
    {
        return delete_user_meta($user, self::META);
    }
}

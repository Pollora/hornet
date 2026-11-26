<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Admin;

/**
 * Who may see the security screen and run a scan: the administrators of a site, and on a network only the super administrators
 * (the files and the scan are shared by every site, and a site administrator must not read what is true of the others).
 *
 * @internal
 */
final class AdminAccess
{
    public static function allowed(): bool
    {
        return is_multisite() ? current_user_can('manage_network_options') : current_user_can('manage_options');
    }
}

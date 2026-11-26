<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

/**
 * What the web server's PHP does with its errors, kept so that the command line and the cron, which have their own setting,
 * report the same thing. It writes only when the answer changes.
 */
final class ErrorDisplayNote
{
    public const string OPTION = 'hornet_error_display';

    /**
     * @param  string|false  $setting  what `ini_get('display_errors')` answered; false when it cannot be asked
     */
    public function record(string|false $setting): void
    {
        if ($setting === false) {
            return;
        }

        $seen = in_array(strtolower(trim($setting)), ['1', 'on', 'yes', 'true', 'stdout'], true) ? 'on' : 'off';

        if (get_site_option(self::OPTION) !== $seen) {
            update_site_option(self::OPTION, $seen);
        }
    }

    public function read(): ?bool
    {
        return match (get_site_option(self::OPTION)) {
            'on' => true,
            'off' => false,
            default => null,
        };
    }
}

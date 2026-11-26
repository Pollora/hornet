<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

/**
 * Notes, on a page of the dashboard, whether PHP shows its errors.
 *
 * Only a page: on an Ajax or a REST request WordPress turns the display off by itself, and the answer would always be "off".
 */
final readonly class ErrorDisplayModule implements Module
{
    public function __construct(private ErrorDisplayNote $note) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('admin_init', $this->look(...));
    }

    public function look(): void
    {
        // With WP_DEBUG on, WordPress sets the display itself and the Debug mode check judges it
        if (wp_doing_ajax() || wp_doing_cron() || wp_is_json_request() || (defined('WP_DEBUG') && WP_DEBUG)) {
            return;
        }

        $this->note->record(ini_get('display_errors'));
    }
}

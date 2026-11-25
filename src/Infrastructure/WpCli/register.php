<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Application\Notification\NotificationService;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Infrastructure\Config\SettingsLoader;
use Pollora\Hornet\Infrastructure\Notification\NotificationFactory;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Pollora\Hornet\Infrastructure\WpCli\HornetCommand;
use Pollora\Hornet\Infrastructure\WpCli\NotifyCommand;
use Pollora\Hornet\Infrastructure\WpCli\WpCliOutput;

/*
 * Loaded by Composer's `autoload.files`: it declares `wp hornet` when the
 * process is WP-CLI, and does nothing anywhere else.
 */

// @codeCoverageIgnoreStart
if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
    WP_CLI::add_command('hornet', new HornetCommand(
        static function (): Report {
            $settings = SettingsLoader::load(get_current_blog_id());

            return (new Auditor(DefaultChecks::all(), $settings->disabledChecks, $settings->ignored))->run(new WordPressEnvironment);
        },
        new WpCliOutput,
    ));

    WP_CLI::add_command('hornet notify', new NotifyCommand(
        static function (): ?NotificationService {
            $settings = SettingsLoader::load(get_current_blog_id());

            return $settings->notifications->channels === []
                ? null
                : NotificationFactory::forWordPress()->service($settings, new WordPressEnvironment, get_bloginfo('name'), home_url());
        },
        new WpCliOutput,
    ));
}

// @codeCoverageIgnoreEnd

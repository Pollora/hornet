<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Closure;
use InvalidArgumentException;
use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Application\Notification\NotificationService;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Config\SettingsLoader;
use Pollora\Hornet\Infrastructure\Login\ClientAddress;
use Pollora\Hornet\Infrastructure\Login\TransientAttemptStore;
use Pollora\Hornet\Infrastructure\Notification\NotificationFactory;

/**
 * Starts the package: builds the modules the settings ask for and attaches them.
 */
final class Bootstrap
{
    /**
     * The entry point of a classic WordPress or Bedrock: the mu-plugin loader calls it.
     */
    public static function fromWordPress(): void
    {
        // Not static: Brain Monkey binds the callbacks it records, and refuses a static closure
        $start = function (): void {
            try {
                $settings = SettingsLoader::load(get_current_blog_id());
            } catch (InvalidArgumentException $invalidArgumentException) {
                self::boot(null, new WordPressHooks, new WordPressEnvironment, $invalidArgumentException->getMessage());

                return;
            }

            self::boot($settings, new WordPressHooks, new WordPressEnvironment($settings));
        };

        if (did_action('plugins_loaded') > 0) {
            $start();

            return;
        }

        add_action('plugins_loaded', $start, 1);
    }

    /**
     * Wrong settings never stop the site: Hornet stays off, and Site Health says why.
     *
     * @param  string|null  $settingsError  why the settings could not be read
     * @param  (Closure(): ?ClientIp)|null  $client  who is asking, when the host knows better than the server variables (Pollora: Laravel's request)
     */
    public static function boot(?Settings $settings, HookRegistrar $hooks, Environment $environment, ?string $settingsError = null, ?Closure $client = null): void
    {
        foreach (self::modules($settings, $environment, $settingsError, $client) as $module) {
            $module->register($hooks);
        }
    }

    /**
     * @return list<Module>
     */
    public static function modules(?Settings $settings, Environment $environment, ?string $settingsError = null, ?Closure $client = null): array
    {
        if (! $settings instanceof Settings) {
            return [new SettingsErrorModule($settingsError ?? 'The settings could not be read.')];
        }

        if (! $settings->enabled) {
            return [];
        }

        $modules = [];

        try {
            if ($settings->siteHealth) {
                $modules[] = new SiteHealthModule(new Auditor(DefaultChecks::all(), $settings->disabledChecks, $settings->ignored), $environment);
            }

            if ($settings->hardening->isActive()) {
                $modules[] = new HardeningModule($settings->hardening);
            }

            if ($settings->headers->isActive()) {
                $modules[] = new HeadersModule($settings->headers);
            }

            if ($settings->login->throttle) {
                $note = new ThrottleProblemNote;
                $modules[] = new LoginThrottleModule(
                    $settings->login,
                    new TransientAttemptStore,
                    new SystemClock,
                    $client ?? static fn (): ?ClientIp => ClientAddress::fromServer($_SERVER, $settings->network),
                    $note->record(...),
                    wp_salt('auth').'hornet-login',
                );
            }

            if ($settings->notifications->channels !== []) {
                // Built once to be sure the settings hold together; the cron builds its own when it runs
                $factory = NotificationFactory::forWordPress();
                $factory->service($settings, $environment, '', '');
                $modules[] = new NotificationModule(static fn (): NotificationService => $factory->service($settings, $environment, get_bloginfo('name'), home_url()), $settings->notifications->schedule);
            }
        } catch (InvalidArgumentException $invalidArgumentException) {
            return [new SettingsErrorModule($invalidArgumentException->getMessage())];
        }

        return $modules;
    }
}

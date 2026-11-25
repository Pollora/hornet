<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Closure;
use InvalidArgumentException;
use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Application\Journal\FloodGuard;
use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Application\Notification\NotificationService;
use Pollora\Hornet\Application\Password\BreachCheck;
use Pollora\Hornet\Application\Scan\ScanJob;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Config\SettingsLoader;
use Pollora\Hornet\Infrastructure\Login\ClientAddress;
use Pollora\Hornet\Infrastructure\Login\TransientAttemptStore;
use Pollora\Hornet\Infrastructure\Notification\NotificationFactory;
use Pollora\Hornet\Infrastructure\WordPress\Abilities\AbilitiesModule;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminBackend;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminPageModule;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminRestModule;
use Pollora\Hornet\Infrastructure\WordPress\Journal\CacheWindowCounter;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalModule;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalPrivacy;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TableJournal;
use Pollora\Hornet\Infrastructure\WordPress\Journal\WpRequestContext;

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
                // The settings are checked now, without touching the database; the auditor itself is built when Site Health asks for it
                new Auditor(DefaultChecks::all($settings->vulnerabilities->enabled), $settings->disabledChecks, $settings->ignored);
                $modules[] = new SiteHealthModule(static fn (): Auditor => AuditorFactory::forWordPress($settings), $environment);
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

            if ($settings->accounts->checkBreached) {
                $modules[] = new PasswordModule(new BreachCheck(new WpHttpClient), $settings->accounts->breachedThreshold);
            }

            if ($settings->accounts->sessionLifetime !== null || $settings->accounts->rememberLifetime !== null) {
                $modules[] = new SessionModule($settings->accounts->sessionLifetime, $settings->accounts->rememberLifetime);
            }

            if ($settings->accounts->requireRoles !== []) {
                $modules[] = new TwoFactorGateModule(
                    $settings->accounts,
                    new TwoFactorEnrollment,
                    new SystemClock,
                    static function (string $url): never {
                        wp_safe_redirect($url);
                        exit;
                    },
                    static fn (): ?object => is_user_logged_in() ? wp_get_current_user() : null,
                );
            }

            $recorder = null;

            if ($settings->journal->enabled) {
                $client ??= static fn (): ?ClientIp => ClientAddress::fromServer($_SERVER, $settings->network);
                $journal = new TableJournal;
                $context = new WpRequestContext($client);
                $recorder = new JournalRecorder($journal, $context, new SystemClock);
                $modules[] = new JournalModule($recorder, $journal, new SystemClock, $settings->journal->retentionDays, new FloodGuard(new CacheWindowCounter), $context->ip(...));
                $modules[] = new JournalPrivacy($journal, $settings->journal->retentionDays);
            }

            // The files are shared by every site of a network: the main site schedules the scan, and runs it, once.
            if (self::ownsScan()) {
                $job = new ScanJob(
                    static fn (Closure $progress): ?Scanner => ScannerFactory::forWordPress($settings, true, $progress),
                    new OptionRunLock('hornet_scan_lock'),
                    new OptionScanStateStore,
                    new SystemClock,
                    static fn (): array => (new OptionScanStore)->last()->unfinished ?? [],
                );

                // The scan listens for the cron when it is scheduled, when its event is still there (turned off, it takes it out), and
                // when the screen can ask for one: on the administration and on the cron, not on a visitor's page
                if ($settings->scanner->schedule !== 'off' || (function_exists('wp_next_scheduled') && wp_next_scheduled(ScanModule::SCAN_HOOK) !== false) || (($settings->admin || $settings->abilities->writes) && self::inAdminOrCron())) {
                    $modules[] = new ScanModule($job, $settings->scanner->schedule);
                }

                if ($settings->abilities->enabled) {
                    $modules[] = new AbilitiesModule(new AdminBackend($settings, $environment, $job, $recorder), $settings->abilities->writes, $recorder, new CacheWindowCounter);
                }

                if ($settings->admin) {
                    // The routes cost one hook on every request: a REST request is only known after this point
                    $modules[] = new AdminRestModule(new AdminBackend($settings, $environment, $job, $recorder), $job);

                    if (function_exists('is_admin') && is_admin()) {
                        $modules[] = new AdminPageModule(is_multisite(), dirname(__DIR__, 3).'/assets/admin', static function (): never {
                            exit;
                        });
                    }
                }
            }

            if ($settings->notifications->channels !== []) {
                // Built once to be sure the settings hold together; the cron builds its own when it runs
                $factory = NotificationFactory::forWordPress();
                // Only to be sure the settings hold together: no auditor of the site, which would read the database on every request
                $factory->service($settings, $environment, '', '', new Auditor(DefaultChecks::all($settings->vulnerabilities->enabled), $settings->disabledChecks, $settings->ignored));
                $modules[] = new NotificationModule(static fn (): NotificationService => $factory->service($settings, $environment, get_bloginfo('name'), home_url()), $settings->notifications->schedule);
            }
        } catch (InvalidArgumentException $invalidArgumentException) {
            return [new SettingsErrorModule($invalidArgumentException->getMessage())];
        }

        return $modules;
    }

    /**
     * Whether the request is one of the administration or of the cron: where a scan that was asked for from the screen is run.
     */
    private static function inAdminOrCron(): bool
    {
        return function_exists('is_admin') && (is_admin() || (function_exists('wp_doing_cron') && wp_doing_cron()));
    }

    /**
     * Whether this site runs the scheduled scan: a single site, or the main site of a network.
     */
    private static function ownsScan(): bool
    {
        return ! function_exists('is_multisite') || ! is_multisite() || is_main_site();
    }
}

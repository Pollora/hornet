<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use InvalidArgumentException;
use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Config\SettingsLoader;

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

            self::boot($settings, new WordPressHooks, new WordPressEnvironment);
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
     */
    public static function boot(?Settings $settings, HookRegistrar $hooks, Environment $environment, ?string $settingsError = null): void
    {
        foreach (self::modules($settings, $environment, $settingsError) as $module) {
            $module->register($hooks);
        }
    }

    /**
     * @return list<Module>
     */
    public static function modules(?Settings $settings, Environment $environment, ?string $settingsError = null): array
    {
        if (! $settings instanceof Settings) {
            return [new SettingsErrorModule($settingsError ?? 'The settings could not be read.')];
        }

        if (! $settings->enabled) {
            return [];
        }

        $modules = [];

        if ($settings->siteHealth) {
            try {
                $modules[] = new SiteHealthModule(new Auditor(DefaultChecks::all(), $settings->disabledChecks, $settings->ignored), $environment);
            } catch (InvalidArgumentException $error) {
                return [new SettingsErrorModule($error->getMessage())];
            }
        }

        return $modules;
    }
}

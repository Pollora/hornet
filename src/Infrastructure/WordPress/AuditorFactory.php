<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Infrastructure\Config\Settings;

/**
 * Builds the auditor of the site: the checks, those that are turned off, and the findings that are set aside: by the settings file, and from the screen.
 *
 * The file wins: a finding it sets aside keeps the reason it gave. A finding set aside from the screen whose check no longer exists
 * (the lookup of vulnerabilities was turned off) is left out, rather than making the audit refuse an id it does not know.
 */
final class AuditorFactory
{
    /**
     * The checks of this site. Every auditor is built from this list, so that the screen, Site Health, the command line and the
     * notifications agree on whether the site is a Pollora one.
     *
     * @return list<Check>
     */
    public static function checks(Settings $settings): array
    {
        return DefaultChecks::all($settings->vulnerabilities->enabled, HostApplication::laravel());
    }

    public static function forWordPress(Settings $settings): Auditor
    {
        $checks = self::checks($settings);
        $known = array_map(static fn (Check $check): string => $check->id(), $checks);
        $screen = [];

        foreach ((new OptionSetAsideStore)->all() as $id => $aside) {
            $screen[$id] = $aside instanceof SetAside ? $aside->reason : '';
        }

        $screen = array_intersect_key($screen, array_flip($known));

        return new Auditor($checks, $settings->disabledChecks, [...$screen, ...$settings->ignored]);
    }
}

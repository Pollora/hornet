<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Checks\AdminAccountCheck;
use Pollora\Hornet\Domain\Audit\Checks\ConfigPermissionsCheck;
use Pollora\Hornet\Domain\Audit\Checks\DebugModeCheck;
use Pollora\Hornet\Domain\Audit\Checks\FileEditorCheck;
use Pollora\Hornet\Domain\Audit\Checks\FileModsCheck;
use Pollora\Hornet\Domain\Audit\Checks\HttpsCheck;
use Pollora\Hornet\Domain\Audit\Checks\LoginProtectionCheck;
use Pollora\Hornet\Domain\Audit\Checks\PhpVersionCheck;
use Pollora\Hornet\Domain\Audit\Checks\SaltsCheck;
use Pollora\Hornet\Domain\Audit\Checks\TwoFactorCheck;
use Pollora\Hornet\Domain\Audit\Checks\TwoFactorCoverageCheck;
use Pollora\Hornet\Domain\Audit\Checks\UserEnumerationCheck;
use Pollora\Hornet\Domain\Audit\Checks\WordPressUpdateCheck;
use Pollora\Hornet\Domain\Audit\Checks\XmlRpcCheck;

/**
 * The checks that ship with the package, in the order a person should read them: the configuration first, then the accounts.
 */
final class DefaultChecks
{
    /**
     * @return list<Check>
     */
    public static function all(): array
    {
        return [
            new SaltsCheck,
            new DebugModeCheck,
            new HttpsCheck,
            new FileEditorCheck,
            new FileModsCheck,
            new ConfigPermissionsCheck,
            new PhpVersionCheck,
            new WordPressUpdateCheck,
            new XmlRpcCheck,
            new UserEnumerationCheck,
            new AdminAccountCheck,
            new LoginProtectionCheck,
            new TwoFactorCheck,
            new TwoFactorCoverageCheck,
        ];
    }
}

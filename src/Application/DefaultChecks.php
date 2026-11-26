<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application;

use Pollora\Hornet\Application\Scan\CoreIntegrityTask;
use Pollora\Hornet\Application\Scan\ExposedFilesTask;
use Pollora\Hornet\Application\Scan\KnownVulnerabilitiesTask;
use Pollora\Hornet\Application\Scan\PluginIntegrityTask;
use Pollora\Hornet\Application\Scan\SuspiciousFilesTask;
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
use Pollora\Hornet\Domain\Audit\Checks\ScanResultCheck;
use Pollora\Hornet\Domain\Audit\Checks\TwoFactorCheck;
use Pollora\Hornet\Domain\Audit\Checks\TwoFactorCoverageCheck;
use Pollora\Hornet\Domain\Audit\Checks\UserEnumerationCheck;
use Pollora\Hornet\Domain\Audit\Checks\WordPressUpdateCheck;
use Pollora\Hornet\Domain\Audit\Checks\XmlRpcCheck;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * The checks that ship with the package, in the order a person should read them: the configuration first, then the accounts.
 */
final class DefaultChecks
{
    /**
     * @return list<Check>
     */
    public static function all(bool $vulnerabilities = false): array
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
            // What the last scan found: the audit reads it and never scans
            new ScanResultCheck(CoreIntegrityTask::ID, Message::mark('WordPress core files')),
            new ScanResultCheck(PluginIntegrityTask::ID, Message::mark('Plugin files')),
            new ScanResultCheck(PluginIntegrityTask::THEMES_ID, Message::mark('Theme files')),
            new ScanResultCheck(SuspiciousFilesTask::ID, Message::mark('Suspicious files')),
            new ScanResultCheck(ExposedFilesTask::ID, Message::mark('Files given away by the site')),
            // Only when the owner opted in: without it the scan never asks, and the check would be "not scanned" for ever
            ...($vulnerabilities ? [new ScanResultCheck(KnownVulnerabilitiesTask::ID, Message::mark('Known vulnerabilities'))] : []),
        ];
    }
}

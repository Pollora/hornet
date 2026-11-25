<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Admin;

use Pollora\Hornet\Domain\Http\ContentSecurityPolicy;
use Pollora\Hornet\Domain\Http\Hsts;
use Pollora\Hornet\Infrastructure\Config\Settings;

/**
 * What Hornet is set to do, in a few lines for a person: which protections are on, and which are not. Never a secret, never a URL:
 * a channel is named by its name and kind, as in `wp hornet notify status`.
 *
 * The screen only shows the settings. They are written in a file (a constant, or `config/hornet.php`), which wins over anything
 * the screen could do; a screen that changed them would let a stolen session switch the protections off.
 */
final class SettingsView
{
    /**
     * @return list<array{group: string, key: string, label: string, on: bool, detail: string}>
     */
    public static function build(Settings $settings): array
    {
        $rows = [];
        $add = static function (string $group, string $key, string $label, bool $on, string $detail = '') use (&$rows): void {
            $rows[] = ['group' => $group, 'key' => $key, 'label' => $label, 'on' => $on, 'detail' => $detail];
        };

        $add('Hardening', 'hardening.xmlrpc', 'Close XML-RPC', $settings->hardening->xmlRpc);
        $add('Hardening', 'hardening.user_enumeration', 'Hide the list of users from visitors', $settings->hardening->userEnumeration);
        $add('Hardening', 'hardening.hide_version', 'Hide the WordPress version', $settings->hardening->hideVersion);
        $add('Hardening', 'hardening.file_editor', 'Refuse the file editor', $settings->hardening->fileEditor);

        $headers = $settings->headers;
        $add('Headers', 'headers.content_type_options', 'X-Content-Type-Options', $headers->contentTypeOptions);
        $add('Headers', 'headers.referrer_policy', 'Referrer-Policy', $headers->referrerPolicy !== null, $headers->referrerPolicy ?? '');
        $add('Headers', 'headers.permissions_policy', 'Permissions-Policy', $headers->permissionsPolicy !== null);
        $add('Headers', 'headers.hsts', 'Strict-Transport-Security', $headers->hsts instanceof Hsts);
        $add('Headers', 'headers.csp', 'Content-Security-Policy', $headers->csp instanceof ContentSecurityPolicy, $headers->csp instanceof ContentSecurityPolicy ? ($headers->csp->enforce ? 'enforced' : 'report only') : '');

        $add('Accounts', 'login.throttle', 'Login throttle', $settings->login->throttle, $settings->login->throttle ? sprintf('%d failures in %d seconds', $settings->login->policy->maxAttempts, $settings->login->policy->window) : '');
        $add('Accounts', 'passwords.check_breached', 'Refuse a password found in a breach', $settings->accounts->checkBreached);
        $add('Accounts', 'session', 'Shorter login sessions', $settings->accounts->sessionLifetime !== null || $settings->accounts->rememberLifetime !== null);
        $add('Accounts', 'two_factor.require_roles', 'Two-factor required', $settings->accounts->requireRoles !== [], implode(', ', $settings->accounts->requireRoles));

        $add('Scanner', 'scanner.schedule', 'Scheduled scan', $settings->scanner->schedule !== 'off', $settings->scanner->schedule);
        $add('Scanner', 'scanner.probes', 'Ask the site what it gives away', $settings->scanner->probes);
        $add('Scanner', 'scanner.probe_uploads', 'Try to run PHP in the uploads', $settings->scanner->probeUploads);
        $add('Scanner', 'vulnerabilities.enabled', 'Look up known vulnerabilities', $settings->vulnerabilities->enabled, $settings->vulnerabilities->enabled ? 'name and version of each plugin and theme go to www.wpvulnerability.net' : '');

        $add('Notifications', 'notifications.channels', 'Notification channels', $settings->notifications->channels !== [], sprintf('%d, %s', count($settings->notifications->channels), $settings->notifications->schedule));
        $add('Audit', 'site_health', 'Site Health tests', $settings->siteHealth);
        $add('Audit', 'disabled_checks', 'Checks turned off', $settings->disabledChecks !== [], implode(', ', $settings->disabledChecks));
        $add('Audit', 'ignore', 'Findings set aside', $settings->ignored !== [], implode(', ', array_keys($settings->ignored)));

        return $rows;
    }
}

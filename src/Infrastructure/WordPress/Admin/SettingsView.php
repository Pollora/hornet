<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Admin;

use Pollora\Hornet\Domain\Http\ContentSecurityPolicy;
use Pollora\Hornet\Domain\Http\Hsts;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\I18n\Translator;
use Pollora\Hornet\Infrastructure\Config\Settings;

/**
 * What Hornet is set to do, in a few lines for a person: which protections are on, and which are not. Never a secret, never a URL:
 * a channel is named by its name and kind, as in `wp hornet notify status`.
 *
 * The screen only shows the settings. They are written in a file (a constant, or `config/pollora/hornet.php`), which wins over anything
 * the screen could do; a screen that changed them would let a stolen session switch the protections off.
 *
 * @internal
 */
final class SettingsView
{
    /**
     * @return list<array{group: string, key: string, label: string, on: bool, detail: string}>
     */
    public static function build(Settings $settings, ?Translator $translator = null): array
    {
        $rows = [];
        $add = static function (string $group, string $key, string $label, bool $on, string|Message $detail = '') use (&$rows, $translator): void {
            $rows[] = ['group' => Message::known($group)->render($translator), 'key' => $key, 'label' => Message::known($label)->render($translator), 'on' => $on, 'detail' => $detail instanceof Message ? $detail->render($translator) : $detail];
        };

        $add(Message::mark('Hardening'), 'hardening.xmlrpc', Message::mark('Close XML-RPC'), $settings->hardening->xmlRpc);
        $add(Message::mark('Hardening'), 'hardening.user_enumeration', Message::mark('Hide the list of users from visitors'), $settings->hardening->userEnumeration);
        $add(Message::mark('Hardening'), 'hardening.hide_version', Message::mark('Hide the WordPress version'), $settings->hardening->hideVersion);
        $add(Message::mark('Hardening'), 'hardening.file_editor', Message::mark('Refuse the file editor'), $settings->hardening->fileEditor);
        $add(Message::mark('Hardening'), 'hardening.restrict_rest_api', Message::mark('Close the REST API to visitors'), $settings->hardening->restrictRestApi, $settings->hardening->restrictRestApi ? implode(', ', $settings->hardening->restApiAllow) : '');

        $headers = $settings->headers;
        $add(Message::mark('Headers'), 'headers.content_type_options', 'X-Content-Type-Options', $headers->contentTypeOptions);
        $add(Message::mark('Headers'), 'headers.referrer_policy', 'Referrer-Policy', $headers->referrerPolicy !== null, $headers->referrerPolicy ?? '');
        $add(Message::mark('Headers'), 'headers.permissions_policy', 'Permissions-Policy', $headers->permissionsPolicy !== null);
        $add(Message::mark('Headers'), 'headers.hsts', 'Strict-Transport-Security', $headers->hsts instanceof Hsts);
        $add(Message::mark('Headers'), 'headers.csp', 'Content-Security-Policy', $headers->csp instanceof ContentSecurityPolicy, $headers->csp instanceof ContentSecurityPolicy ? ($headers->csp->enforce ? Message::of('enforced') : Message::of('report only')) : '');

        $add(Message::mark('Accounts'), 'login.throttle', Message::mark('Login throttle'), $settings->login->throttle, $settings->login->throttle ? Message::of('%1$d failures in %2$d seconds', $settings->login->policy->maxAttempts, $settings->login->policy->window) : '');
        $add(Message::mark('Accounts'), 'passwords.check_breached', Message::mark('Refuse a password found in a breach'), $settings->accounts->checkBreached);
        $add(Message::mark('Accounts'), 'session', Message::mark('Shorter login sessions'), $settings->accounts->sessionLifetime !== null || $settings->accounts->rememberLifetime !== null);
        $add(Message::mark('Accounts'), 'two_factor.require_roles', Message::mark('Two-factor required'), $settings->accounts->requireRoles !== [], implode(', ', $settings->accounts->requireRoles));

        $add(Message::mark('Scanner'), 'scanner.schedule', Message::mark('Scheduled scan'), $settings->scanner->schedule !== 'off', self::frequency($settings->scanner->schedule));
        $add(Message::mark('Scanner'), 'scanner.probes', Message::mark('Ask the site what it gives away'), $settings->scanner->probes);
        $add(Message::mark('Scanner'), 'scanner.probe_uploads', Message::mark('Try to run PHP in the uploads'), $settings->scanner->probeUploads);
        $add(Message::mark('Scanner'), 'vulnerabilities.enabled', Message::mark('Look up known vulnerabilities'), $settings->vulnerabilities->enabled, $settings->vulnerabilities->enabled ? Message::of('name and version of each plugin and theme go to www.wpvulnerability.net') : '');
        $add(Message::mark('Scanner'), 'vulnerabilities.composer', Message::mark('Look up Composer packages'), $settings->vulnerabilities->auditsPackages(), $settings->vulnerabilities->auditsPackages() ? Message::of('name and version of each Packagist package of the project go to api.osv.dev') : '');

        $add(Message::mark('Journal'), 'journal.enabled', Message::mark('Security journal'), $settings->journal->enabled, $settings->journal->enabled ? Message::plural('kept %d day', 'kept %d days', $settings->journal->retentionDays, $settings->journal->retentionDays) : '');
        $add(Message::mark('Agents'), 'abilities.enabled', Message::mark('Abilities for AI agents (read)'), $settings->abilities->enabled);
        $add(Message::mark('Agents'), 'abilities.writes', Message::mark('Abilities for AI agents (start a scan, set aside)'), $settings->abilities->writes, $settings->abilities->writes ? Message::of('at most 20 changes an hour per person') : '');
        $add(Message::mark('Notifications'), 'notifications.channels', Message::mark('Notification channels'), $settings->notifications->channels !== [], self::channels(count($settings->notifications->channels), $settings->notifications->schedule));
        $add(Message::mark('Audit'), 'site_health', Message::mark('Site Health tests'), $settings->siteHealth);
        $add(Message::mark('Audit'), 'disabled_checks', Message::mark('Checks turned off'), $settings->disabledChecks !== [], implode(', ', $settings->disabledChecks));
        $add(Message::mark('Audit'), 'ignore', Message::mark('Findings set aside'), $settings->ignored !== [], implode(', ', array_keys($settings->ignored)));

        return $rows;
    }

    /**
     * How often the scan runs by itself. Not scheduled says nothing more: the row already reads "Off".
     */
    private static function frequency(string $schedule): string|Message
    {
        return match ($schedule) {
            'hourly' => Message::of('every hour'),
            'twicedaily' => Message::of('twice a day'),
            'daily' => Message::of('once a day'),
            default => '',
        };
    }

    /**
     * How many channels, and how often the audit that feeds them runs by itself. "off" is the schedule, not the channels: it is said
     * in words, so that it is not read as a channel turned off.
     */
    private static function channels(int $count, string $schedule): string|Message
    {
        if ($count === 0) {
            return '';
        }

        return Message::join(', ', [
            Message::plural('%d channel', '%d channels', $count, $count),
            match ($schedule) {
                'hourly' => Message::of('audit every hour'),
                'twicedaily' => Message::of('audit twice a day'),
                'daily' => Message::of('audit once a day'),
                default => Message::of('no scheduled audit'),
            },
        ]);
    }
}

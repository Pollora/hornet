<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Domain\Audit\Checks\HttpsCheck;
use Pollora\Hornet\Domain\Audit\Checks\XmlRpcCheck;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Audit\XmlRpcState;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Admin\SettingsView;
use Pollora\Hornet\Infrastructure\WordPress\SettingsErrorModule;
use Pollora\Hornet\Infrastructure\WordPress\SiteHealthInfo;
use Pollora\Hornet\Infrastructure\WordPress\SiteHealthModule;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;

const MUT_WP_EVERY_SETTING = [
    'hardening' => ['xmlrpc' => true, 'user_enumeration' => true, 'hide_version' => true, 'file_editor' => true, 'restrict_rest_api' => true, 'rest_api_allow' => ['wp/v2/posts', 'wp/v2/pages']],
    'headers' => ['content_type_options' => true, 'referrer_policy' => 'no-referrer', 'permissions_policy' => 'camera=()', 'hsts' => ['max_age' => 31536000], 'csp' => ['policy' => "default-src 'self'", 'mode' => 'enforce']],
    'login' => ['throttle' => true, 'max_attempts' => 4, 'window' => 700],
    'passwords' => ['check_breached' => true],
    'session' => ['remember_lifetime' => 7200],
    'two_factor' => ['require_roles' => ['administrator', 'editor']],
    'scanner' => ['schedule' => 'daily', 'probes' => true, 'probe_uploads' => true],
    'vulnerabilities' => ['enabled' => true, 'composer' => true],
    'journal' => ['enabled' => true, 'retention_days' => 1],
    'abilities' => ['enabled' => true, 'writes' => true],
    'notifications' => ['schedule' => 'hourly', 'channels' => ['a' => ['type' => 'mail', 'to' => 'ops@example.com']]],
    'disabled_checks' => ['https', 'salts'],
    'ignore' => [['check' => 'file-editor', 'reason' => 'wanted'], ['check' => 'debug-mode', 'reason' => 'wanted']],
];

/** @return list<array{string, string, string, bool, string}> */
function mutWpRows(array $config): array
{
    return array_map(static fn (array $row): array => [$row['group'], $row['key'], $row['label'], $row['on'], $row['detail']], SettingsView::build(Settings::fromArray($config)));
}

it('lists every setting that is on, with its group, its name and its detail', function (): void {
    expect(mutWpRows(MUT_WP_EVERY_SETTING))->toBe([
        ['Hardening', 'hardening.xmlrpc', 'Close XML-RPC', true, ''],
        ['Hardening', 'hardening.user_enumeration', 'Hide the list of users from visitors', true, ''],
        ['Hardening', 'hardening.hide_version', 'Hide the WordPress version', true, ''],
        ['Hardening', 'hardening.file_editor', 'Refuse the file editor', true, ''],
        ['Hardening', 'hardening.restrict_rest_api', 'Close the REST API to visitors', true, 'wp/v2/posts, wp/v2/pages'],
        ['Headers', 'headers.content_type_options', 'X-Content-Type-Options', true, ''],
        ['Headers', 'headers.referrer_policy', 'Referrer-Policy', true, 'no-referrer'],
        ['Headers', 'headers.permissions_policy', 'Permissions-Policy', true, ''],
        ['Headers', 'headers.hsts', 'Strict-Transport-Security', true, ''],
        ['Headers', 'headers.csp', 'Content-Security-Policy', true, 'enforced'],
        ['Accounts', 'login.throttle', 'Login throttle', true, '4 failures in 700 seconds'],
        ['Accounts', 'passwords.check_breached', 'Refuse a password found in a breach', true, ''],
        ['Accounts', 'session', 'Shorter login sessions', true, ''],
        ['Accounts', 'two_factor.require_roles', 'Two-factor required', true, 'administrator, editor'],
        ['Scanner', 'scanner.schedule', 'Scheduled scan', true, 'once a day'],
        ['Scanner', 'scanner.probes', 'Ask the site what it gives away', true, ''],
        ['Scanner', 'scanner.probe_uploads', 'Try to run PHP in the uploads', true, ''],
        ['Scanner', 'vulnerabilities.enabled', 'Look up known vulnerabilities', true, 'name and version of each plugin and theme go to www.wpvulnerability.net'],
        ['Scanner', 'vulnerabilities.composer', 'Look up Composer packages', true, 'name and version of each Packagist package of the project go to api.osv.dev'],
        ['Journal', 'journal.enabled', 'Security journal', true, 'kept 1 day'],
        ['Agents', 'abilities.enabled', 'Abilities for AI agents (read)', true, ''],
        ['Agents', 'abilities.writes', 'Abilities for AI agents (start a scan, set aside)', true, 'at most 20 changes an hour per person'],
        ['Notifications', 'notifications.channels', 'Notification channels', true, '1 channel, audit every hour'],
        ['Audit', 'site_health', 'Site Health tests', true, ''],
        ['Audit', 'disabled_checks', 'Checks turned off', true, 'https, salts'],
        ['Audit', 'ignore', 'Findings set aside', true, 'file-editor, debug-mode'],
    ]);
});

it('says nothing more of a setting that is off', function (): void {
    $rows = mutWpRows(['site_health' => false, 'scanner' => ['probes' => false]]);

    expect($rows)->toHaveCount(26)
        ->and(array_filter(array_column($rows, 3)))->toBe([])
        ->and(array_filter(array_column($rows, 4)))->toBe([]);
});

function mutWpDetail(array $config, string $key): string
{
    return array_column(SettingsView::build(Settings::fromArray($config)), 'detail', 'key')[$key];
}

it('says how often the scan and the audit of the channels run', function (string $schedule, string $scan, string $audit): void {
    $channels = ['a' => ['type' => 'mail', 'to' => 'a@example.com'], 'b' => ['type' => 'mail', 'to' => 'b@example.com']];

    expect(mutWpDetail(['scanner' => ['schedule' => $schedule]], 'scanner.schedule'))->toBe($scan)
        ->and(mutWpDetail(['notifications' => ['schedule' => $schedule, 'channels' => $channels]], 'notifications.channels'))->toBe('2 channels, '.$audit);
})->with([
    ['off', '', 'no scheduled audit'],
    ['hourly', 'every hour', 'audit every hour'],
    ['twicedaily', 'twice a day', 'audit twice a day'],
    ['daily', 'once a day', 'audit once a day'],
]);

it('says report only for a policy that is not enforced, and kept days in the plural', function (): void {
    expect(mutWpDetail(['headers' => ['csp' => ['policy' => "default-src 'self'"]]], 'headers.csp'))->toBe('report only')
        ->and(mutWpDetail(['journal' => ['enabled' => true, 'retention_days' => 30]], 'journal.enabled'))->toBe('kept 30 days')
        ->and(mutWpDetail(['session' => ['lifetime' => 3600]], 'session'))->toBe('')
        ->and(array_column(SettingsView::build(Settings::fromArray(['session' => ['lifetime' => 3600]])), 'on', 'key')['session'])->toBeTrue();
});

it('describes a finding for Site Health, line by line', function (): void {
    $finding = Finding::fail('core-integrity', Severity::High, 'Files differ.', ['wp-load.php', 'index.php'], 'Reinstall <it>.');

    expect(SiteHealthModule::format($finding, 'Core'))->toBe([
        'label' => 'Core',
        'status' => 'critical',
        'badge' => ['label' => 'Security', 'color' => 'red'],
        'description' => '<p>Files differ.</p><ul><li>wp-load.php</li><li>index.php</li></ul>',
        'actions' => '<p>Reinstall &lt;it&gt;.</p>',
        'test' => 'hornet_core_integrity',
    ])->and(SiteHealthModule::format($finding->ignoredFor('Known.'), 'Core')['description'])->toBe('<p>Files differ.</p><ul><li>wp-load.php</li><li>index.php</li></ul><p>Set aside on purpose: Known.</p>')
        ->and(SiteHealthModule::format(Finding::pass('x', 'Fine.'), 'X')['description'])->toBe('<p>Fine.</p>')
        ->and(SiteHealthModule::format(Finding::unknown('x', 'Unsure.'), 'X')['badge'])->toBe(['label' => 'Not verified', 'color' => 'gray']);
});

it('names each test after Hornet and its check, and builds the auditor once', function (): void {
    $built = 0;
    $module = new SiteHealthModule(function () use (&$built): Auditor {
        $built++;

        return new Auditor([new XmlRpcCheck]);
    }, new FakeEnvironment);

    $tests = $module->tests(['direct' => 'not a list']);
    $tests['direct']['hornet_xml_rpc']['test']();
    $module->tests([]);

    expect(array_keys($tests['direct']))->toBe(['hornet_xml_rpc'])
        ->and($tests['direct']['hornet_xml_rpc']['label'])->toBe('Hornet: '.(new XmlRpcCheck)->label())
        ->and($built)->toBe(1);
});

it('shows the wrong setting as one critical test beside the others', function (): void {
    $tests = (new SettingsErrorModule('Wrong <value>.'))->tests(['direct' => ['mine' => ['label' => 'Mine']], 'async' => []]);
    $result = $tests['direct']['hornet_settings']['test']();

    expect(array_keys($tests['direct']))->toBe(['mine', 'hornet_settings'])
        ->and($tests['direct']['hornet_settings']['label'])->toBe('Hornet: settings')
        ->and($result)->toBe([
            'label' => 'Hornet is off: its settings are wrong',
            'status' => 'critical',
            'badge' => ['label' => 'Security', 'color' => 'red'],
            'description' => '<p>Wrong &lt;value&gt;.</p>',
            'actions' => '',
            'test' => 'hornet_settings',
        ]);
});

/** @param array<string, mixed> $config */
function mutWpInformation(array $config, ?Closure $auditor = null): array
{
    $auditor ??= static fn (): Auditor => new Auditor([new HttpsCheck]);

    return (new SiteHealthInfo(Settings::fromArray($config), $auditor, new FakeEnvironment(constants: ['FORCE_SSL_ADMIN' => true])))->information([])['pollora-hornet'];
}

it('gives Site Health the section of Hornet, field by field', function (): void {
    $section = mutWpInformation(['hardening' => ['restrict_rest_api' => true, 'rest_api_allow' => ['wp/v2/posts']], 'scanner' => ['probes' => false], 'site_health' => true]);

    expect($section['label'])->toBe('Hornet')
        ->and($section['fields']['version'])->toBe(['label' => 'Version', 'value' => (string) InstalledVersions::getPrettyVersion('pollora/hornet'), 'private' => false])
        ->and($section['fields']['protections'])->toBe(['label' => 'Turned on', 'value' => [
            'hardening.restrict_rest_api' => 'Close the REST API to visitors (wp/v2/posts)',
            'site_health' => 'Site Health tests',
        ], 'private' => true])
        ->and($section['fields']['last_scan'])->toBe(['label' => 'Last scan', 'value' => 'Never', 'private' => false])
        ->and($section['fields']['score'])->toBe(['label' => 'Security score', 'value' => '100 out of 100, grade A', 'private' => true]);
});

it('says that nothing is turned on, and that a score is only part of the picture', function (): void {
    $provisional = static fn (): Auditor => new Auditor([new HttpsCheck, new XmlRpcCheck]);
    $environment = new class extends FakeEnvironment
    {
        public function xmlRpcState(): XmlRpcState
        {
            throw new RuntimeException('cannot tell');
        }
    };
    $fields = (new SiteHealthInfo(Settings::fromArray(['site_health' => false, 'scanner' => ['probes' => false]]), $provisional, $environment))->information([])['pollora-hornet']['fields'];

    expect($fields['protections']['value'])->toBe('Nothing')
        ->and($fields['score']['value'])->toEndWith(' (part of the audit could not be verified)')
        ->and($fields['score']['value'])->toContain(' out of 100, grade ');
});

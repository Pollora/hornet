<?php

declare(strict_types=1);

use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Admin\SettingsView;
use Pollora\Hornet\Tests\Fixtures\ArraySecrets;

/**
 * @param  array<string, mixed>  $config
 * @return array<string, array{on: bool, detail: string, label: string, group: string, key: string}>
 */
function settingsRows(array $config = []): array
{
    $rows = [];

    foreach (SettingsView::build(Settings::fromArray($config, new ArraySecrets(['SLACK' => 'https://hooks.slack.test/services/T0/B0/SECRETSECRET']))) as $row) {
        $rows[$row['key']] = $row;
    }

    return $rows;
}

it('says everything is off on a site that was told nothing, except what is on by itself', function (): void {
    $rows = settingsRows();

    expect(array_filter($rows, static fn (array $row): bool => $row['on']))->toHaveKeys(['scanner.probes', 'site_health'])
        ->and(count(array_filter($rows, static fn (array $row): bool => $row['on'])))->toBe(2);
});

it('says what is on, with the number that matters', function (): void {
    $rows = settingsRows([
        'hardening' => ['xmlrpc' => true, 'file_editor' => true],
        'headers' => ['content_type_options' => true, 'referrer_policy' => 'same-origin', 'csp' => ['mode' => 'report-only', 'policy' => "default-src 'self'"]],
        'login' => ['throttle' => true, 'max_attempts' => 3, 'window' => 600],
        'two_factor' => ['require_roles' => ['administrator', 'editor']],
        'scanner' => ['schedule' => 'daily', 'probe_uploads' => true],
        'vulnerabilities' => ['enabled' => true],
        'disabled_checks' => ['file-mods'],
        'ignore' => [['check' => 'xml-rpc', 'reason' => 'Jetpack']],
    ]);

    expect($rows['hardening.xmlrpc']['on'])->toBeTrue()->and($rows['hardening.hide_version']['on'])->toBeFalse()
        ->and($rows['headers.referrer_policy']['detail'])->toBe('same-origin')
        ->and($rows['headers.csp']['detail'])->toBe('report only')
        ->and($rows['login.throttle']['detail'])->toBe('3 failures in 600 seconds')
        ->and($rows['two_factor.require_roles']['detail'])->toBe('administrator, editor')
        ->and($rows['scanner.schedule']['detail'])->toBe('once a day')->and($rows['scanner.probe_uploads']['on'])->toBeTrue()
        ->and($rows['vulnerabilities.enabled']['detail'])->toContain('wpvulnerability.net')
        ->and($rows['disabled_checks']['detail'])->toBe('file-mods')->and($rows['ignore']['detail'])->toBe('xml-rpc');
});

it('never shows where a channel goes: not a URL, not a token', function (): void {
    $rows = settingsRows(['notifications' => ['channels' => ['slack' => ['type' => 'slack', 'url' => ['env' => 'SLACK']]]]]);

    expect($rows['notifications.channels'])->toMatchArray(['on' => true, 'detail' => '1 channel, audit once a day'])
        ->and(json_encode($rows))->not->toContain('hooks.slack')->not->toContain('SECRETSECRET');
});

it('says in words how often the channels are fed, so that no schedule does not read as a channel turned off', function (string $schedule, string $detail): void {
    $rows = settingsRows(['notifications' => ['schedule' => $schedule, 'channels' => [
        'ops' => ['type' => 'mail', 'to' => ['ops@example.com']],
        'team' => ['type' => 'mail', 'to' => ['team@example.com']],
    ]]]);

    expect($rows['notifications.channels']['detail'])->toBe($detail);
})->with([
    ['off', '2 channels, no scheduled audit'],
    ['hourly', '2 channels, audit every hour'],
    ['twicedaily', '2 channels, audit twice a day'],
    ['daily', '2 channels, audit once a day'],
]);

it('says nothing more about notifications when no channel is set', function (): void {
    expect(settingsRows()['notifications.channels'])->toMatchArray(['on' => false, 'detail' => '']);
});

it('says in words how often the scan runs, and nothing more when it is not scheduled', function (string $schedule, bool $on, string $detail): void {
    expect(settingsRows(['scanner' => ['schedule' => $schedule]])['scanner.schedule'])->toMatchArray(['on' => $on, 'detail' => $detail]);
})->with([
    ['off', false, ''],
    ['hourly', true, 'every hour'],
    ['twicedaily', true, 'twice a day'],
    ['daily', true, 'once a day'],
]);

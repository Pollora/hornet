<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Domain\Audit\Checks\HttpsCheck;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\SiteHealthInfo;
use Pollora\Hornet\Infrastructure\WordPress\SiteHealthModule;
use Pollora\Hornet\Tests\Fixtures\ArraySecrets;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

function hornetInfo(array $config, FakeEnvironment $environment, ?Closure $auditor = null): array
{
    $auditor ??= static fn (): Auditor => new Auditor(DefaultChecks::all(false, null));
    $hooks = new RecordingHooks;
    (new SiteHealthModule($auditor, $environment, information: new SiteHealthInfo(Settings::fromArray($config, new ArraySecrets), $auditor, $environment)))->register($hooks);

    return $hooks->callback('debug_information')(['wp-core' => []]);
}

it('adds a section, and keeps the others', function (): void {
    $information = hornetInfo([], new FakeEnvironment);

    expect(array_keys($information))->toBe(['wp-core', 'pollora-hornet'])
        ->and(array_keys($information['pollora-hornet']['fields']))->toBe(['version', 'protections', 'last_scan', 'score']);
});

it('leaves what shows where the site is weak out of the copy', function (): void {
    $fields = hornetInfo([], new FakeEnvironment)['pollora-hornet']['fields'];

    expect($fields['protections']['private'])->toBeTrue()
        ->and($fields['score']['private'])->toBeTrue()
        ->and($fields['version']['private'])->toBeFalse()
        ->and($fields['last_scan']['private'])->toBeFalse();
});

it('names what is turned on, never an address, a proxy or the URL of a channel', function (): void {
    $fields = hornetInfo([
        'hardening' => ['xmlrpc' => true],
        'network' => ['trusted_proxies' => ['10.1.2.3']],
        'login' => ['throttle' => true, 'allow_ips' => ['203.0.113.50']],
        'headers' => ['csp' => ['policy' => "default-src 'self'", 'report_uri' => 'https://reports.example.com/csp']],
        'notifications' => ['channels' => ['ops' => ['type' => 'slack', 'url' => 'https://hooks.example.com/T0/secret']]],
    ], new FakeEnvironment)['pollora-hornet']['fields'];

    $all = json_encode($fields, JSON_UNESCAPED_SLASHES);

    expect(array_keys($fields['protections']['value']))->toContain('hardening.xmlrpc', 'login.throttle', 'notifications.channels')
        ->not->toContain('hardening.hide_version')
        ->and($all)->not->toContain('10.1.2.3', '203.0.113.50', 'example.com');
});

it('says when the last scan was, or that there was none', function (): void {
    $scan = new ScanSnapshot(new DateTimeImmutable('2026-11-20 10:30:00 UTC'), []);

    expect(hornetInfo([], new FakeEnvironment)['pollora-hornet']['fields']['last_scan']['value'])->toBe('Never')
        ->and(hornetInfo([], new FakeEnvironment(scan: $scan))['pollora-hornet']['fields']['last_scan']['value'])->toBe('2026-11-20 10:30 UTC');
});

it('gives no number when the audit cannot be made', function (): void {
    $fields = hornetInfo([], new FakeEnvironment, static fn (): Auditor => throw new RuntimeException('no database'))['pollora-hornet']['fields'];

    expect($fields['score']['value'])->toBe('Could not be read');
});

it('says a score or a scan could not be read, never a clean one, and keeps the score out of the copy', function (): void {
    $environment = new class extends FakeEnvironment
    {
        public function lastScan(): ?ScanSnapshot
        {
            throw new RuntimeException('database gone');
        }
    };
    $fields = hornetInfo([], $environment, static fn (): Auditor => throw new RuntimeException('no audit'))['pollora-hornet']['fields'];

    expect($fields['last_scan']['value'])->toBe('Could not be read')
        ->and($fields['score']['value'])->toBe('Could not be read')
        ->and($fields['score']['private'])->toBeTrue();
});

it('gives the score alone when everything could be verified', function (): void {
    $fields = hornetInfo([], new FakeEnvironment(constants: ['FORCE_SSL_ADMIN' => true]), static fn (): Auditor => new Auditor([new HttpsCheck]))['pollora-hornet']['fields'];

    expect($fields['score']['value'])->toBe('100 out of 100, grade A');
});

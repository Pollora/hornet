<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Domain\Audit\Checks\SaltsCheck;
use Pollora\Hornet\Domain\Audit\Checks\XmlRpcCheck;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Infrastructure\WordPress\SettingsErrorModule;
use Pollora\Hornet\Infrastructure\WordPress\SiteHealthModule;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

function siteHealthTests(FakeEnvironment $environment, array $disabled = [], array $ignored = []): array
{
    $hooks = new RecordingHooks;
    (new SiteHealthModule(new Auditor(DefaultChecks::all(), $disabled, $ignored), $environment))->register($hooks);

    return $hooks->callback('site_status_tests')(['direct' => ['existing' => ['label' => 'Existing', 'test' => 'x']], 'async' => ['a' => 1]]);
}

it('adds one direct test per check and keeps the tests that were there', function (): void {
    $tests = siteHealthTests(new FakeEnvironment);

    expect($tests['direct'])->toHaveKey('existing')->toHaveKey('hornet_salts')->toHaveKey('hornet_xml_rpc')
        ->and($tests['async'])->toBe(['a' => 1])
        ->and(array_keys($tests['direct']))->toHaveCount(1 + count(DefaultChecks::all()));
});

it('leaves out a disabled check', function (): void {
    expect(siteHealthTests(new FakeEnvironment, ['xml-rpc'])['direct'])->not->toHaveKey('hornet_xml_rpc');
});

it('answers a test with the finding of its check', function (): void {
    $result = siteHealthTests(new FakeEnvironment(xmlRpc: true))['direct']['hornet_xml_rpc']['test']();

    expect($result['status'])->toBe('recommended')
        ->and($result['badge'])->toBe(['label' => 'Security', 'color' => 'orange'])
        ->and($result['description'])->toContain('XML-RPC is enabled')
        ->and($result['actions'])->toContain('xmlrpc_enabled');
});

it('runs the audit once for all the tests of a request', function (): void {
    $counter = new class extends FakeEnvironment
    {
        public int $reads = 0;

        public function xmlRpcEnabled(): bool
        {
            $this->reads++;

            return parent::xmlRpcEnabled();
        }
    };

    $tests = siteHealthTests($counter)['direct'];
    $tests['hornet_xml_rpc']['test']();
    $tests['hornet_salts']['test']();

    expect($counter->reads)->toBe(1);
});

it('maps each verdict to a Site Health status', function (Finding $finding, string $status, string $color): void {
    $result = SiteHealthModule::format($finding, 'Label');

    expect($result['status'])->toBe($status)->and($result['badge']['color'])->toBe($color)->and($result['label'])->toBe('Label');
})->with([
    'pass' => [fn () => Finding::pass('x', 'ok'), 'good', 'blue'],
    'unknown' => [fn () => Finding::unknown('x', 'cannot tell'), 'good', 'gray'],
    'high' => [fn () => Finding::fail('x', Severity::High, 'bad'), 'critical', 'red'],
    'critical' => [fn () => Finding::fail('x', Severity::Critical, 'bad'), 'critical', 'red'],
    'medium' => [fn () => Finding::fail('x', Severity::Medium, 'meh'), 'recommended', 'orange'],
    'info' => [fn () => Finding::fail('x', Severity::Info, 'advice'), 'recommended', 'orange'],
    'ignored' => [fn () => Finding::fail('x', Severity::High, 'bad')->ignoredFor('Accepted.'), 'good', 'blue'],
]);

it('shows why a finding was set aside, and its details, escaped', function (): void {
    $result = SiteHealthModule::format(Finding::fail('x', Severity::Medium, 'S', ['<b>detail</b>'], 'Do it.')->ignoredFor('Because <i>.'), 'L');

    expect($result['description'])->toContain('<li>&lt;b&gt;detail&lt;/b&gt;</li>')->toContain('Set aside on purpose: Because &lt;i&gt;.')
        ->and($result['actions'])->toBe('');
});

it('names the fix of an open finding in the actions', function (): void {
    expect(SiteHealthModule::format(Finding::fail('x', Severity::Medium, 'S', [], 'Do it.'), 'L')['actions'])->toBe('<p>Do it.</p>');
});

it('tells when a test has no finding, instead of passing it', function (): void {
    $module = new SiteHealthModule(new Auditor([new XmlRpcCheck]), new FakeEnvironment);
    $result = $module->result(new SaltsCheck);

    expect($result['badge']['label'])->toBe('Not verified');
});

it('shows one critical test when the settings are wrong', function (): void {
    $hooks = new RecordingHooks;
    (new SettingsErrorModule('Unknown Hornet setting "enable".'))->register($hooks);
    $tests = $hooks->callback('site_status_tests')([]);
    $result = $tests['direct']['hornet_settings']['test']();

    expect($result['status'])->toBe('critical')->and($result['description'])->toContain('Unknown Hornet setting');
});

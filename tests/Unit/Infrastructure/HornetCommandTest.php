<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Infrastructure\WpCli\HornetCommand;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\RecordingOutput;
use Pollora\Hornet\Tests\Fixtures\Samples;

function commandFor(FakeEnvironment $environment, RecordingOutput $output, array $ignored = []): HornetCommand
{
    return new HornetCommand(fn (): Report => (new Auditor(DefaultChecks::all(), [], $ignored))->run($environment), $output);
}

it('lists the open findings and the checks it could not verify, and fails on a high one by default', function (): void {
    $output = new RecordingOutput;
    $command = commandFor(new FakeEnvironment(https: false, config: null), $output);

    expect(fn () => $command->status())->toThrow(RuntimeException::class, 'high or above');

    $statuses = array_column($output->rows, 'status', 'check');

    expect($statuses['https'])->toBe('open')->and($statuses['config-permissions'])->toBe('unknown')
        ->and($output->rows[0]['severity'])->toBe('high')
        ->and($output->format)->toBe('table');
});

it('succeeds when no open finding reaches the threshold, and still says what is left', function (): void {
    $output = new RecordingOutput;
    // Nothing above medium: the default fails on high
    commandFor(new FakeEnvironment(constants: FakeEnvironment::salts(), xmlRpc: true), $output)->status();

    expect($output->warnings[0])->toContain('open')
        ->and(implode("\n", $output->lines))->toContain('  xml-rpc: Close it unless');
});

it('fails at the threshold it is given, and never with none', function (): void {
    $environment = new FakeEnvironment(constants: FakeEnvironment::salts(), xmlRpc: true);

    expect(fn () => commandFor($environment, new RecordingOutput)->status([], ['fail-on' => 'medium']))->toThrow(RuntimeException::class, 'medium or above');

    $output = new RecordingOutput;
    commandFor(new FakeEnvironment(https: false), $output)->status([], ['fail-on' => 'none']);

    expect($output->warnings)->not->toBe([]);
});

it('says so when everything passes', function (): void {
    $output = new RecordingOutput;
    $environment = new FakeEnvironment(
        constants: [...FakeEnvironment::salts(), 'DISALLOW_FILE_EDIT' => true, 'DISALLOW_FILE_MODS' => true, 'FORCE_SSL_ADMIN' => true],
        active: [Component::Portcullis, Component::TwoFactor],
        scan: Samples::cleanScan(),
    );

    commandFor($environment, $output)->status([], ['all' => true]);

    expect($output->successes[0])->toContain('22 checks: 22 pass, 0 open')
        ->and($output->rows)->toHaveCount(22);
});

it('does not list what passes unless asked', function (): void {
    $output = new RecordingOutput;
    commandFor(new FakeEnvironment(constants: FakeEnvironment::salts(), xmlRpc: true), $output)->status();

    expect(array_column($output->rows, 'status'))->not->toContain('pass');
});

it('marks a finding set aside', function (): void {
    $output = new RecordingOutput;
    commandFor(new FakeEnvironment(constants: FakeEnvironment::salts(), xmlRpc: true), $output, ['xml-rpc' => 'Jetpack.'])->status([], ['all' => true]);

    expect(array_column($output->rows, 'status', 'check')['xml-rpc'])->toBe('ignored');
});

it('prints JSON with the counts and the findings', function (): void {
    $output = new RecordingOutput;
    commandFor(new FakeEnvironment(constants: FakeEnvironment::salts(), xmlRpc: true), $output)->status([], ['format' => 'json']);
    $decoded = json_decode($output->lines[0], true);

    expect($decoded['counts']['open'])->toBeGreaterThan(0)
        ->and($decoded['findings'][0])->toHaveKeys(['check', 'outcome', 'severity', 'summary', 'details', 'fix', 'ignored'])
        ->and($output->successes)->toBe([])->and($output->warnings)->toBe([]);
});

it('refuses an unknown format or severity', function (): void {
    expect(fn () => commandFor(new FakeEnvironment, new RecordingOutput)->status([], ['format' => 'xml']))->toThrow(RuntimeException::class, 'Unknown format')
        ->and(fn () => commandFor(new FakeEnvironment, new RecordingOutput)->status([], ['fail-on' => 'severe']))->toThrow(RuntimeException::class, 'Unknown severity');
});

it('reports wrong settings as an error and runs nothing', function (): void {
    $command = new HornetCommand(fn (): Report => throw InvalidSettingsException::unknownKey('enable', ''), new RecordingOutput);

    expect(fn () => $command->status())->toThrow(RuntimeException::class, 'Unknown Hornet setting "enable"');
});

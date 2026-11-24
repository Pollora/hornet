<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\UnknownCheckException;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;

it('runs every check once and reports them in order', function (): void {
    $report = (new Auditor(DefaultChecks::all()))->run(new FakeEnvironment);

    expect($report->findings)->toHaveCount(count(DefaultChecks::all()))
        ->and($report->findings[0]->checkId)->toBe('salts');
});

it('does not run a disabled check', function (): void {
    $auditor = new Auditor(DefaultChecks::all(), ['salts', 'xml-rpc']);

    expect(array_map(static fn (Check $check): string => $check->id(), $auditor->checks()))->not->toContain('salts', 'xml-rpc')
        ->and($auditor->run(new FakeEnvironment)->find('salts'))->toBeNull();
});

it('sets a finding aside on a written reason, and keeps the reason', function (): void {
    $report = (new Auditor(DefaultChecks::all(), [], ['xml-rpc' => 'Jetpack needs it.']))->run(new FakeEnvironment(xmlRpc: true));
    $finding = $report->find('xml-rpc');

    expect($finding?->outcome)->toBe(Outcome::Fail)
        ->and($finding?->isOpen())->toBeFalse()
        ->and($finding?->ignoredReason)->toBe('Jetpack needs it.');
});

it('refuses a setting that names a check that does not exist', function (): void {
    expect(fn (): Auditor => new Auditor(DefaultChecks::all(), ['saltz']))->toThrow(UnknownCheckException::class, 'saltz')
        ->and(fn (): Auditor => new Auditor(DefaultChecks::all(), [], ['nope' => 'why']))->toThrow(UnknownCheckException::class, 'Known checks');
});

it('reports a check that crashes instead of hiding it', function (): void {
    $broken = new class implements Check
    {
        public function id(): string
        {
            return 'broken';
        }

        public function label(): string
        {
            return 'Broken';
        }

        public function run(Environment $environment): Finding
        {
            throw new RuntimeException('secret detail that must not leak');
        }
    };

    $finding = (new Auditor([$broken]))->run(new FakeEnvironment)->find('broken');

    expect($finding?->outcome)->toBe(Outcome::Unknown)
        ->and($finding?->summary)->toContain('RuntimeException')->not->toContain('secret detail')
        ->and($finding?->severity)->toBe(Severity::Info);
});

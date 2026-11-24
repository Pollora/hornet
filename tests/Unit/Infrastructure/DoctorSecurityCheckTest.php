<?php

declare(strict_types=1);

use Pollora\Doctor\Domain\Enums\CheckStatus;
use Pollora\Doctor\Domain\Enums\RunContext;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Infrastructure\Pollora\DoctorSecurityCheck;

function doctorCheck(Finding ...$findings): DoctorSecurityCheck
{
    return new DoctorSecurityCheck(fn (): Report => new Report(array_values($findings)));
}

it('has a stable id and runs from the console only, where it cannot repeat the Site Health tests', function (): void {
    $check = doctorCheck();

    expect($check->id())->toBe('hornet-security')->and($check->label())->not->toBe('')->and($check->runsIn())->toBe([RunContext::Console]);
});

it('is ok when nothing is open, and counts what could not be verified', function (): void {
    $result = doctorCheck(Finding::pass('a', 'fine'), Finding::unknown('b', 'cannot tell'))->run(RunContext::Console);

    expect($result->status)->toBe(CheckStatus::Ok)->and($result->summary)->toBe('1 of 2 security checks pass.')->and($result->details)->toBe(['1 could not be verified.']);
});

it('is a warning for findings below high, with the first fix', function (): void {
    $result = doctorCheck(Finding::fail('a', Severity::Medium, 'Medium thing.', [], 'Do this.'))->run(RunContext::Console);

    expect($result->status)->toBe(CheckStatus::Warning)->and($result->summary)->toBe('1 open security finding(s).')
        ->and($result->details)->toBe(['[medium] Medium thing.'])->and($result->fix)->toContain('Do this.');
});

it('is an error from a high finding up', function (): void {
    expect(doctorCheck(Finding::fail('a', Severity::High, 'Bad.'), Finding::fail('b', Severity::Low, 'Meh.'))->run(RunContext::Console)->status)->toBe(CheckStatus::Error);
});

it('ignores a finding that was set aside', function (): void {
    expect(doctorCheck(Finding::fail('a', Severity::High, 'Bad.')->ignoredFor('Accepted.'))->run(RunContext::Console)->status)->toBe(CheckStatus::Ok);
});

it('lists ten findings and counts the rest', function (): void {
    $findings = array_map(static fn (int $i): Finding => Finding::fail('c'.$i, Severity::Low, 'Finding '.$i.'.'), range(1, 12));
    $result = doctorCheck(...$findings)->run(RunContext::Console);

    expect($result->details)->toHaveCount(11)->and($result->details[10])->toBe('… and 2 more.');
});

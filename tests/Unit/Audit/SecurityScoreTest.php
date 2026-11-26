<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\SecurityScore;
use Pollora\Hornet\Domain\Audit\Severity;

function scoreOf(Finding ...$findings): SecurityScore
{
    return SecurityScore::of(new Report(array_values($findings)));
}

function open_(string $id, Severity $severity): Finding
{
    return Finding::fail($id, $severity, 'x');
}

it('is 100 and an A when nothing is open', function (): void {
    $score = scoreOf(Finding::pass('a', 'fine'), Finding::pass('b', 'fine'));

    expect([$score->value, $score->grade, $score->isProvisional(), $score->costs])->toBe([100, 'A', false, []]);
});

it('takes points by severity, and says which cost the most first', function (): void {
    $score = scoreOf(open_('low', Severity::Low), open_('medium', Severity::Medium), open_('info', Severity::Info));

    expect($score->value)->toBe(88)->and($score->grade)->toBe('B')->and($score->costs)->toBe(['medium' => 8, 'low' => 3, 'info' => 1]);
});

it('holds the grade back with the worst that is open, whatever else is shut', function (Severity $severity, int $value, string $grade): void {
    $score = scoreOf(open_('x', $severity), ...array_map(static fn (int $n): Finding => Finding::pass('p'.$n, 'ok'), range(1, 20)));

    expect([$score->value, $score->grade])->toBe([$value, $grade]);
})->with([
    'one critical is an E at best' => [Severity::Critical, 39, 'E'],
    'one high is a C at best' => [Severity::High, 74, 'C'],
    'one medium alone stays an A' => [Severity::Medium, 92, 'A'],
]);

it('never goes under zero', function (): void {
    $score = scoreOf(...array_map(static fn (int $n): Finding => open_('c'.$n, Severity::Critical), range(1, 8)));

    expect($score->value)->toBe(0)->and($score->grade)->toBe('F');
});

it('charges nothing for what was set aside, but an accepted high risk keeps the site from an A', function (): void {
    $minor = scoreOf(open_('a', Severity::Low)->ignoredFor('known and fine'));
    $major = scoreOf(open_('a', Severity::High)->ignoredFor('known and fine'));

    expect([$minor->value, $minor->grade, $minor->accepted])->toBe([100, 'A', 1])
        ->and([$major->value, $major->grade, $major->accepted])->toBe([89, 'B', 1]);
});

it('says the score is provisional when part could not be verified, and charges nothing for it', function (): void {
    $score = scoreOf(Finding::pass('a', 'ok'), Finding::unknown('b', 'never scanned'));

    expect($score->value)->toBe(100)->and($score->isProvisional())->toBeTrue()->and([$score->verified, $score->total])->toBe([1, 2]);
});

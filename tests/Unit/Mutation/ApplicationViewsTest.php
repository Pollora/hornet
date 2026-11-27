<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Abilities\AgentView;
use Pollora\Hornet\Application\Admin\StatusView;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Domain\Scan\ScanState;

// What the screen is given

$now = new DateTimeImmutable('2026-11-25 10:00:00 UTC');

/**
 * @param  list<Finding>  $findings
 * @param  array<string, SetAside>  $asides
 * @param  list<string>  $inFile
 */
function mutAppStatus(array $findings, ?ScanSnapshot $scan = null, ?ScanState $state = null, array $asides = [], array $inFile = [], array $tasks = []): array
{
    return StatusView::build(new Report($findings), ['a' => 'Check A'], $scan, $state ?? ScanState::idle(), new DateTimeImmutable('2026-11-25 10:00:00 UTC'), $tasks, $asides, $inFile);
}

it('puts first what is open, the worst first, then what is unknown, set aside, and passing', function (): void {
    $status = mutAppStatus([
        Finding::pass('pass', 'ok'),
        Finding::fail('ignored', Severity::Critical, 'x')->ignoredFor('why'),
        Finding::unknown('unknown', 'x'),
        Finding::fail('info', Severity::Info, 'x'),
        Finding::fail('low', Severity::Low, 'x'),
        Finding::fail('medium', Severity::Medium, 'x'),
        Finding::fail('high', Severity::High, 'x'),
        Finding::fail('critical', Severity::Critical, 'x'),
    ]);

    expect(array_column($status['findings'], 'check'))->toBe(['critical', 'high', 'medium', 'low', 'info', 'unknown', 'ignored', 'pass'])
        ->and(array_column($status['findings'], 'status'))->toBe(['open', 'open', 'open', 'open', 'open', 'unknown', 'ignored', 'pass'])
        ->and($status['worst'])->toBe('critical')
        ->and($status['counts']['total'])->toBe(8)
        ->and($status['score'])->toHaveKey('grade');
});

it('shows each finding with its words, and the id of a check that has no label', function (): void {
    $row = mutAppStatus([Finding::fail('a', Severity::High, 'sum', ['d1'], 'fix it')])['findings'][0];
    $other = mutAppStatus([Finding::pass('b', 'ok')])['findings'][0];

    expect($row)->toBe(['check' => 'a', 'label' => 'Check A', 'status' => 'open', 'outcome' => 'fail', 'severity' => 'high', 'summary' => 'sum', 'details' => ['d1'], 'fix' => 'fix it', 'ignored' => null, 'ignored_by' => null, 'ignored_login' => null, 'ignored_at' => null, 'can_set_aside' => true, 'can_restore' => false])
        ->and($other['label'])->toBe('b')
        ->and($other['can_set_aside'])->toBeFalse();
});

it('says who set a finding aside, and lets only the screen restore what the screen did', function (): void {
    $aside = new SetAside('a', 'why', 3, 'olivier', 123);
    $ignored = Finding::fail('a', Severity::High, 'x')->ignoredFor('why');

    $screen = mutAppStatus([$ignored], asides: ['a' => $aside])['findings'][0];
    $file = mutAppStatus([$ignored], asides: ['a' => $aside], inFile: ['a'])['findings'][0];
    $neither = mutAppStatus([$ignored])['findings'][0];
    $open = mutAppStatus([Finding::fail('a', Severity::High, 'x')], asides: ['a' => $aside], inFile: ['a'])['findings'][0];

    expect([$screen['ignored'], $screen['ignored_by'], $screen['ignored_login'], $screen['ignored_at'], $screen['can_restore'], $screen['can_set_aside']])->toBe(['why', 'screen', 'olivier', 123, true, false])
        ->and([$file['ignored_by'], $file['ignored_login'], $file['ignored_at'], $file['can_restore']])->toBe(['file', null, null, false])
        ->and([$neither['ignored_by'], $neither['ignored_login'], $neither['ignored_at'], $neither['can_restore']])->toBe(['screen', null, null, false])
        // Locked by the file: the screen cannot set it aside
        ->and([$open['ignored_by'], $open['ignored_login'], $open['ignored_at'], $open['can_set_aside'], $open['can_restore']])->toBe([null, null, null, false, false]);
});

it('says nothing of a scan that never ran', function (): void {
    $scan = mutAppStatus([], tasks: ['a', 'z'])['scan'];

    expect($scan)->toBe(['at' => null, 'age' => null, 'unfinished' => [], 'running' => false, 'status' => 'idle', 'task' => null, 'tasks' => [
        ['id' => 'a', 'label' => 'Check A', 'status' => 'none', 'summary' => 'Not scanned yet.'],
        ['id' => 'z', 'label' => 'z', 'status' => 'none', 'summary' => 'Not scanned yet.'],
    ]]);
});

it('tells the age of the last scan, what is unfinished and where each task stands', function () use ($now): void {
    $snapshot = new ScanSnapshot($now->modify('-90 seconds'), ['a' => Finding::fail('a', Severity::Low, 'found')], [], ['a']);
    $future = new ScanSnapshot($now->modify('+90 seconds'), []);

    $scan = mutAppStatus([], $snapshot, tasks: ['a'])['scan'];

    expect($scan['at'])->toBe($now->getTimestamp() - 90)
        ->and($scan['age'])->toBe(90)
        ->and($scan['unfinished'])->toBe(['a'])
        ->and($scan['tasks'])->toBe([['id' => 'a', 'label' => 'Check A', 'status' => 'open', 'summary' => 'found']])
        ->and(mutAppStatus([], $future)['scan']['age'])->toBe(0);
});

it('tells the running task only while the scan is alive', function () use ($now): void {
    $alive = mutAppStatus([], state: new ScanState(ScanState::RUNNING, 'a', $now->getTimestamp() - 60))['scan'];
    $dead = mutAppStatus([], state: new ScanState(ScanState::RUNNING, 'a', $now->getTimestamp() - 3600))['scan'];

    expect([$alive['running'], $alive['status'], $alive['task']])->toBe([true, 'running', 'a'])
        ->and([$dead['running'], $dead['status'], $dead['task']])->toBe([false, 'idle', null]);
});

// What an agent is given

it('gives an agent a summary of known fields only, cut and typed', function (): void {
    $summary = AgentView::summary([
        'counts' => ['open' => 2, 'bad name' => 1, 'text' => 'x', 'pass' => 3, 'ABC' => 4, str_repeat('a', 21) => 1, "x\n" => 1],
        'score' => ['value' => 80, 'grade' => 'BIG'],
        'worst' => str_repeat('w', 40),
        'scan' => ['age' => 12, 'running' => 1, 'task' => str_repeat('t', 90)],
        'journal' => ['enabled' => 1],
        'secret' => 'never',
    ]);

    expect(array_keys($summary))->toBe(['counts', 'score', 'grade', 'worst_severity', 'last_scan_seconds_ago', 'scan_running', 'scan_task', 'journal_enabled'])
        ->and($summary['counts'])->toBe(['open' => 2, 'pass' => 3])
        ->and($summary['score'])->toBe(80)
        ->and(mb_strlen($summary['grade']))->toBe(1)
        ->and(mb_strlen($summary['worst_severity']))->toBe(20)
        ->and($summary['last_scan_seconds_ago'])->toBe(12)
        ->and($summary['scan_running'])->toBeTrue()
        ->and(mb_strlen($summary['scan_task']))->toBe(60)
        ->and($summary['journal_enabled'])->toBeTrue();
});

it('gives an agent nothing of what has the wrong shape', function (mixed $status): void {
    $summary = AgentView::summary(['counts' => $status, 'score' => $status, 'worst' => 5, 'scan' => $status, 'journal' => $status]);

    expect($summary)->toBe(['counts' => [], 'score' => null, 'grade' => null, 'worst_severity' => null, 'last_scan_seconds_ago' => null, 'scan_running' => false, 'scan_task' => null, 'journal_enabled' => false]);
})->with(['a text' => ['x'], 'nothing' => [null]]);

/**
 * @return array<string, mixed>
 */
function mutAppRow(string $check, string $status = 'open', string $severity = 'high', array $more = []): array
{
    return ['check' => $check, 'status' => $status, 'severity' => $severity, ...$more];
}

it('filters the findings an agent asks for', function (string $show, ?string $severity, array $expected): void {
    $status = ['findings' => [mutAppRow('o'), mutAppRow('u', 'unknown', 'info'), mutAppRow('i', 'ignored', 'low'), mutAppRow('p', 'pass', 'info'), 'junk']];

    $answer = AgentView::findings($status, $show, $severity, 50, 0);

    expect(array_column($answer['items'], 'check'))->toBe($expected)->and($answer['total'])->toBe(count($expected));
})->with([
    ['all', null, ['o', 'u', 'i', 'p']],
    ['attention', null, ['o', 'u']],
    ['open', null, ['o']],
    ['unknown', null, ['u']],
    ['ignored', null, ['i']],
    ['pass', null, ['p']],
    ['all', 'info', ['u', 'p']],
    ['open', 'low', []],
]);

it('pages the findings within bounds', function (int $limit, int $offset, array $expected): void {
    $status = ['findings' => array_map(static fn (int $n): array => mutAppRow('c'.$n), range(1, 60))];

    $answer = AgentView::findings($status, 'all', null, $limit, $offset);

    expect($answer['total'])->toBe(60)
        ->and(count($answer['items']))->toBe($expected[0])
        ->and($answer['items'][0]['check'] ?? null)->toBe($expected[1]);
})->with([
    'too many' => [1000, 0, [50, 'c1']],
    'none' => [0, 0, [1, 'c1']],
    'negative' => [-5, -5, [1, 'c1']],
    'a page' => [2, 3, [2, 'c4']],
    'past the end' => [5, 60, [0, null]],
]);

it('cuts every text of a finding an agent reads, and gives five lines of details at most', function (): void {
    $long = str_repeat('x', 900);
    $finding = mutAppRow($long, $long, $long, ['label' => $long, 'summary' => $long, 'details' => ['k' => $long, 'l' => "a\u{200B}b", 2, null, 'e', 'f', 'g'], 'ignored' => $long, 'ignored_by' => $long, 'can_set_aside' => 1, 'fix' => $long, 'other' => 'never']);

    $row = AgentView::remediation(['findings' => ['junk', $finding]], $long);

    expect(array_keys($row))->toBe(['check', 'label', 'status', 'severity', 'summary', 'details', 'set_aside_reason', 'set_aside_by', 'can_set_aside', 'fix'])
        ->and(array_map(mb_strlen(...), [$row['check'], $row['label'], $row['status'], $row['severity'], $row['summary'], $row['set_aside_reason'], $row['set_aside_by'], $row['fix']]))->toBe([60, 100, 20, 20, 300, 300, 10, 600])
        ->and($row['details'])->toHaveCount(5)
        ->and(mb_strlen($row['details'][0]))->toBe(200)
        ->and($row['details'][1])->toBe('ab')
        ->and($row['can_set_aside'])->toBeTrue();
});

it('gives nulls for what a finding does not say, and nothing for a check that does not exist', function (): void {
    $row = AgentView::remediation(['findings' => [['check' => 'a', 'details' => 'x']]], 'a');

    expect($row)->toBe(['check' => 'a', 'label' => '', 'status' => '', 'severity' => '', 'summary' => '', 'details' => [], 'set_aside_reason' => null, 'set_aside_by' => null, 'can_set_aside' => false, 'fix' => null])
        ->and(AgentView::remediation(['findings' => [['check' => 'a']]], 'b'))->toBeNull()
        ->and(AgentView::remediation(['findings' => 'x'], 'a'))->toBeNull()
        ->and(AgentView::remediation([], 'a'))->toBeNull()
        ->and(AgentView::findings(['findings' => 'x'], 'all', null, 5, 0))->toBe(['total' => 0, 'items' => []])
        ->and(AgentView::findings([], 'all', null, 5, 0))->toBe(['total' => 0, 'items' => []]);
});

it('gives an agent the journal with every text cut and the address shortened', function (): void {
    $long = str_repeat('y', 300);
    $page = ['enabled' => 1, 'total' => 9, 'page' => 2, 'pages' => 3, 'events' => ['junk', ['at' => 5, 'type' => $long, 'source' => $long, 'actor' => $long, 'subject' => $long, 'ip' => '203.0.113.77', 'details' => ['from' => 'a', 'password' => 'b']], ['at' => 'x', 'details' => 'x']]];

    $answer = AgentView::journal($page, 10);

    expect([$answer['enabled'], $answer['total'], $answer['page'], $answer['pages']])->toBe([true, 9, 2, 3])
        ->and($answer['events'])->toHaveCount(2)
        ->and(array_map(mb_strlen(...), [$answer['events'][0]['type'], $answer['events'][0]['source'], $answer['events'][0]['actor'], $answer['events'][0]['subject']]))->toBe([60, 20, 60, 120])
        ->and($answer['events'][0]['at'])->toBe(5)
        ->and($answer['events'][0]['network'])->toBe('203.0.113.0')
        ->and($answer['events'][0]['details'])->toBe(['from' => 'a'])
        ->and($answer['events'][1])->toBe(['at' => 0, 'type' => '', 'source' => '', 'actor' => '', 'subject' => '', 'network' => '', 'details' => []]);
});

it('bounds the events it gives an agent, and has sound defaults', function (int $limit, int $count): void {
    $answer = AgentView::journal(['events' => array_fill(0, 60, ['at' => 1])], $limit);

    expect($answer['events'])->toHaveCount($count)
        ->and([$answer['enabled'], $answer['total'], $answer['page'], $answer['pages']])->toBe([false, 0, 1, 1])
        ->and(AgentView::journal(['events' => 'x'], 5)['events'])->toBe([]);
})->with([[1000, 50], [0, 1], [3, 3]]);

it('puts a finding set aside before a passing one, however low it is', function (): void {
    $status = StatusView::build(new Report([Finding::pass('p', 'ok'), Finding::fail('i', Severity::Info, 'x')->ignoredFor('why')]), [], null, ScanState::idle(), new DateTimeImmutable, []);

    expect(array_column($status['findings'], 'check'))->toBe(['i', 'p']);
});

<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Admin\StatusView;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Domain\Scan\ScanState;

function statusView(?ScanSnapshot $scan = null, ?ScanState $state = null, string $now = '2026-11-25 12:00:00'): array
{
    $report = new Report([
        Finding::pass('https', 'The site uses https.'),
        Finding::fail('xml-rpc', Severity::Medium, 'XML-RPC answers.', ['pingback.ping'], 'Close it.'),
        Finding::unknown('config-permissions', 'Cannot be read.'),
        Finding::fail('file-mods', Severity::Low, 'Updates from the dashboard.')->ignoredFor('Updates come from the dashboard on this site.'),
        Finding::fail('salts', Severity::Critical, 'The salts are the sample ones.', [], 'Generate new ones.'),
    ]);

    return StatusView::build($report, ['https' => 'HTTPS', 'xml-rpc' => 'XML-RPC', 'salts' => 'Salts', 'core-integrity' => 'WordPress core files'], $scan, $state ?? ScanState::idle(), new DateTimeImmutable($now.' UTC'), ['core-integrity', 'plugin-integrity']);
}

it('puts what asks for attention first, the worst first, then what is not known, then the rest', function (): void {
    $view = statusView();

    expect(array_column($view['findings'], 'check'))->toBe(['salts', 'xml-rpc', 'config-permissions', 'file-mods', 'https'])
        ->and(array_column($view['findings'], 'status'))->toBe(['open', 'open', 'unknown', 'ignored', 'pass'])
        ->and($view['worst'])->toBe('critical')
        ->and($view['counts'])->toBe(['total' => 5, 'pass' => 1, 'open' => 2, 'ignored' => 1, 'unknown' => 1]);
});

it('gives each finding a name for a person, and falls back on the id', function (): void {
    $findings = array_column(statusView()['findings'], null, 'check');

    expect($findings['salts']['label'])->toBe('Salts')->and($findings['config-permissions']['label'])->toBe('config-permissions')
        ->and($findings['xml-rpc']['details'])->toBe(['pingback.ping'])->and($findings['xml-rpc']['fix'])->toBe('Close it.')
        ->and($findings['file-mods']['ignored'])->toBe('Updates come from the dashboard on this site.');
});

it('says that nothing was ever scanned, and what each task of the scan is at', function (): void {
    $scan = statusView()['scan'];

    expect($scan['at'])->toBeNull()->and($scan['age'])->toBeNull()->and($scan['running'])->toBeFalse()->and($scan['status'])->toBe('idle')
        ->and($scan['tasks'])->toBe([
            ['id' => 'core-integrity', 'label' => 'WordPress core files', 'status' => 'none', 'summary' => 'Not scanned yet.'],
            ['id' => 'plugin-integrity', 'label' => 'plugin-integrity', 'status' => 'none', 'summary' => 'Not scanned yet.'],
        ]);
});

it('tells when the last scan was, how old it is, what it found, and what it did not finish', function (): void {
    $snapshot = new ScanSnapshot(new DateTimeImmutable('2026-11-25 10:00:00 UTC'), ['core-integrity' => Finding::fail('core-integrity', Severity::High, 'A file differs.')], [], ['suspicious-files']);

    $scan = statusView($snapshot)['scan'];

    expect($scan['at'])->toBe(strtotime('2026-11-25 10:00:00 UTC'))->and($scan['age'])->toBe(7200)->and($scan['unfinished'])->toBe(['suspicious-files'])
        ->and($scan['tasks'][0])->toBe(['id' => 'core-integrity', 'label' => 'WordPress core files', 'status' => 'open', 'summary' => 'A file differs.']);
});

it('says which task a scan that is running is at, and does not wait for one that has died', function (): void {
    $now = strtotime('2026-11-25 12:00:00 UTC');
    $running = statusView(state: new ScanState(ScanState::RUNNING, 'plugin-integrity', $now - 30))['scan'];
    $dead = statusView(state: new ScanState(ScanState::RUNNING, 'plugin-integrity', $now - 3600))['scan'];
    $queued = statusView(state: new ScanState(ScanState::QUEUED, null, $now - 5))['scan'];

    expect($running)->toMatchArray(['running' => true, 'status' => 'running', 'task' => 'plugin-integrity'])
        ->and($dead)->toMatchArray(['running' => false, 'status' => 'idle', 'task' => null])
        ->and($queued)->toMatchArray(['running' => true, 'status' => 'queued', 'task' => null]);
});

it('keeps the text of a finding as text: it holds what the site wrote, and is never markup', function (): void {
    $report = new Report([Finding::fail('x', Severity::High, '<img src=x onerror=alert(1)>', ['<script>alert(1)</script>'])]);

    $view = StatusView::build($report, [], null, ScanState::idle(), new DateTimeImmutable('2026-11-25 UTC'), []);

    expect($view['findings'][0]['summary'])->toBe('<img src=x onerror=alert(1)>')->and(json_encode($view))->toBeString();
});

it('says who set a finding aside, and where, and what the screen may do with it', function (): void {
    $report = new Report([
        Finding::fail('xml-rpc', Severity::Medium, 'XML-RPC answers.'),
        Finding::fail('file-mods', Severity::Low, 'Updates from the dashboard.')->ignoredFor('In the file.'),
        Finding::fail('https', Severity::High, 'No https.')->ignoredFor('From the screen, for now.'),
        Finding::pass('salts', 'Fine.'),
        Finding::unknown('core-integrity', 'Never scanned.'),
    ]);
    $aside = ['https' => new SetAside('https', 'From the screen, for now.', 7, 'olivier', 1795600000), 'file-mods' => new SetAside('file-mods', 'Older, from the screen.', 7, 'olivier', 1)];

    $view = StatusView::build($report, [], null, ScanState::idle(), new DateTimeImmutable('2026-11-25 UTC'), [], $aside, ['file-mods']);
    $by = array_column($view['findings'], null, 'check');

    // The file wins over the screen: its reason is the one shown, and the screen cannot touch it
    expect($by['xml-rpc'])->toMatchArray(['ignored_by' => null, 'can_set_aside' => true, 'can_restore' => false])
        ->and($by['file-mods'])->toMatchArray(['ignored_by' => 'file', 'ignored_login' => null, 'can_set_aside' => false, 'can_restore' => false])
        ->and($by['https'])->toMatchArray(['ignored_by' => 'screen', 'ignored_login' => 'olivier', 'ignored_at' => 1795600000, 'can_set_aside' => false, 'can_restore' => true])
        // Nothing to hide in what passes, and what is not known cannot be set aside either
        ->and($by['salts'])->toMatchArray(['can_set_aside' => false, 'can_restore' => false])
        ->and($by['core-integrity'])->toMatchArray(['can_set_aside' => false]);
});

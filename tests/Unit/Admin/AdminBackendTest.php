<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\Exceptions\SetAsideException;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Domain\Scan\ScanState;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminAccess;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStore;
use Pollora\Hornet\Infrastructure\WordPress\OptionSetAsideStore;
use Pollora\Hornet\Infrastructure\WordPress\ScanModule;
use Pollora\Hornet\Tests\Fixtures\AdminSite;

beforeEach(function (): void {
    $this->admin = new AdminSite;
});

it('lets only the administrators in, and on a network only the super administrators', function (bool $multisite, array $capabilities, bool $allowed): void {
    $this->admin->site->multisite = $multisite;
    Functions\when('current_user_can')->alias(static fn (string $capability): bool => in_array($capability, $capabilities, true));

    expect(AdminAccess::allowed())->toBe($allowed);
})->with([
    'an administrator' => [false, ['manage_options'], true],
    'an editor' => [false, ['edit_posts'], false],
    'the administrator of one site of a network' => [true, ['manage_options'], false],
    'a super administrator' => [true, ['manage_network_options'], true],
]);

it('gives the audit, the scan, the settings and the channels by name and kind, never where they lead', function (): void {
    $path = implode('', ['hook', '-path-not-to-show']);
    $backend = $this->admin->backend([
        'notifications' => ['channels' => ['team' => ['type' => 'mail', 'to' => 'ops@example.com', 'min_severity' => 'high'], 'chat' => ['type' => 'slack', 'url' => 'https://hooks.example.com/'.$path]]],
        'journal' => ['enabled' => true, 'retention_days' => 12],
        'ignore' => [['check' => 'https', 'reason' => 'Behind a proxy that does TLS.']],
    ]);
    (new OptionScanStore)->save(new ScanSnapshot(new DateTimeImmutable('-1 hour'), []));
    (new OptionSetAsideStore)->put(new SetAside('salts', 'Rotated by the host.', 7, 'olivier', time()));

    $status = $backend->status();
    $findings = array_column($status['findings'], null, 'check');

    expect($status['channels'])->toBe([['name' => 'team', 'type' => 'mail', 'min_severity' => 'high'], ['name' => 'chat', 'type' => 'slack', 'min_severity' => 'medium']])
        ->and(json_encode($status))->not->toContain($path)->not->toContain('ops@example.com')
        ->and($status['network'])->toBeFalse()
        ->and($status['journal'])->toBe(['enabled' => true, 'retention_days' => 12])
        ->and($findings['salts']['status'])->toBe('ignored')
        ->and($findings['salts']['ignored'])->toBe('Rotated by the host.')
        ->and($findings['https']['status'])->toBe('ignored')
        ->and($findings['https']['can_set_aside'])->toBeFalse()
        ->and($findings['file-editor']['status'])->toBe('open')
        ->and($findings['file-editor']['label'])->not->toBe('file-editor')
        ->and($status['scan']['age'])->toBeGreaterThanOrEqual(3600)->toBeLessThan(3700)
        ->and($status['scan']['overdue'])->toBeFalse()
        ->and($status['scan']['cron'])->toBe(['disabled' => false, 'url' => 'https://example.com/wp-cron.php'])
        ->and(array_column($status['scan']['tasks'], 'id'))->toContain('core-integrity', 'suspicious-files')
        ->and(array_column($status['settings'], 'key'))->toContain('hardening.xmlrpc', 'journal.enabled');
});

it('says a scan is overdue when the cron has not run it, unless another way did', function (int $due, ?int $scanned, bool $overdue): void {
    $this->admin->nextScan = time() + $due;

    if ($scanned !== null) {
        (new OptionScanStore)->save(new ScanSnapshot(new DateTimeImmutable('@'.(time() + $scanned)), []));
    }

    expect($this->admin->backend()->status()['scan']['overdue'])->toBe($overdue);
})->with([
    'due long ago' => [-300, null, true],
    'only just' => [-30, null, false],
    'not yet' => [300, null, false],
    'answered since' => [-300, -100, false],
    'an older scan' => [-300, -900, true],
]);

it('no longer waits for a queued scan that another way answered', function (?int $scanned, string $status): void {
    $this->admin->state->save(new ScanState(ScanState::QUEUED, null, time() - 100));

    if ($scanned !== null) {
        (new OptionScanStore)->save(new ScanSnapshot(new DateTimeImmutable('@'.(time() + $scanned)), []));
    }

    expect($this->admin->backend()->state()->status)->toBe($status);
})->with([[null, 'queued'], [-500, 'queued'], [-10, 'idle']]);

it('keeps saying a scan runs while it runs', function (): void {
    $this->admin->state->save(new ScanState(ScanState::RUNNING, 'core-integrity', time()));
    (new OptionScanStore)->save(new ScanSnapshot(new DateTimeImmutable('+1 minute'), []));

    expect($this->admin->backend()->state()->status)->toBe(ScanState::RUNNING);
});

it('queues a scan, and starts the cron at once', function (): void {
    $result = $this->admin->backend()->requestScan();

    expect($result['queued'])->toBeTrue()
        ->and($result['state']['status'])->toBe('queued')
        ->and($this->admin->scheduled)->toHaveCount(1)
        ->and($this->admin->scheduled[0][1])->toBe(ScanModule::NOW_HOOK)
        ->and($this->admin->scheduled[0][0])->toBeLessThanOrEqual(time())
        ->and($this->admin->spawned)->toBe(1);
});

it('does not queue a second scan', function (string $status): void {
    $this->admin->state->save(new ScanState($status, null, time()));

    $result = $this->admin->backend()->requestScan();

    expect($result)->toMatchArray(['queued' => false, 'reason' => 'A scan is already in progress.'])
        ->and($result['state']['status'])->toBe($status)
        ->and($this->admin->scheduled)->toBe([])
        ->and($this->admin->spawned)->toBe(0);
})->with([ScanState::QUEUED, ScanState::RUNNING]);

it('reads one page of the journal, bounded, and filters what it is asked', function (array $params, int $perPage, int $page, string $where): void {
    $this->admin->db->answers = ['205', [['id' => '1', 'occurred_at' => '2026-11-25 10:00:00', 'type' => 'login.success', 'source' => 'web', 'details' => '[]']]];

    $result = $this->admin->backend(['journal' => ['enabled' => true, 'retention_days' => 30]])->journal($params);

    expect($result['enabled'])->toBeTrue()
        ->and($result['total'])->toBe(205)
        ->and($result['page'])->toBe($page)
        ->and($result['pages'])->toBe((int) ceil(205 / $perPage))
        ->and($result['retention_days'])->toBe(30)
        ->and($result['types'])->toContain('login', 'plugin')
        ->and($result['events'][0]['type'])->toBe('login.success')
        ->and($this->admin->db->ran[0])->toBe('SELECT COUNT(*) FROM `wp_hornet_journal` '.$where)
        ->and($this->admin->db->ran[1])->toBe('SELECT * FROM `wp_hornet_journal` '.$where.' ORDER BY occurred_at DESC, id DESC LIMIT '.$perPage.' OFFSET '.(($page - 1) * $perPage));
})->with([
    'nothing asked' => [[], 25, 1, ''],
    'empty values' => [['type' => '', 'source' => '', 'user' => 0, 'page' => 0, 'per_page' => 0], 1, 1, ''],
    'everything' => [['type' => 'login.', 'source' => 'cli', 'user' => 3, 'page' => 3, 'per_page' => 10], 10, 3, "WHERE type LIKE 'login.%' AND source = 'cli' AND user_id = 3"],
    'too much' => [['per_page' => 500, 'source' => 'mars', 'user' => -3], 100, 1, ''],
]);

it('reads nothing when the journal is off', function (): void {
    expect($this->admin->backend()->journal(['page' => 4]))->toMatchArray(['enabled' => false, 'events' => [], 'total' => 0, 'page' => 1, 'pages' => 1])
        ->and($this->admin->db->ran)->toBe([]);
});

it('has one page when the journal is empty', function (): void {
    $this->admin->db->answers = ['0', []];

    expect($this->admin->backend(['journal' => ['enabled' => true]])->journal([])['pages'])->toBe(1);
});

it('sets an open finding aside under the name of who did it, says it in the journal, and brings it back', function (string $via, array $extra): void {
    $backend = $this->admin->backend();

    $aside = $backend->setAside('salts', 'Rotated by the host.', $via);

    expect([$aside->check, $aside->reason, $aside->userId, $aside->login])->toBe(['salts', 'Rotated by the host.', 7, 'olivier'])
        ->and((new OptionSetAsideStore)->all())->toHaveKey('salts');

    $backend->restore('salts', $via);

    expect((new OptionSetAsideStore)->all())->toBe([])
        ->and(array_map(static fn (Event $event): array => [$event->type, $event->subject, $event->details], $this->admin->journal->events))->toBe([
            ['hornet.finding_set_aside', 'check:salts', ['reason' => 'Rotated by the host.', ...$extra]],
            ['hornet.finding_restored', 'check:salts', $extra],
        ]);
})->with([['', []], ['ability', ['via' => 'ability']]]);

it('sets aside without a journal too', function (): void {
    $this->admin->backend(recording: false)->setAside('salts', 'Rotated by the host.');

    expect((new OptionSetAsideStore)->all())->toHaveKey('salts')->and($this->admin->journal->events)->toBe([]);
});

it('refuses to set aside what it cannot', function (string $check, string $reason, array $config, string $kind): void {
    try {
        $this->admin->backend($config)->setAside($check, $reason);
        $this->fail('No exception.');
    } catch (SetAsideException $setAsideException) {
        expect($setAsideException->kind)->toBe($kind)->and((new OptionSetAsideStore)->all())->toBe([]);
    }
})->with([
    'an unknown check' => ['nope', 'A long enough reason.', [], 'unknown'],
    'a check of the file' => ['salts', 'A long enough reason.', ['ignore' => [['check' => 'salts', 'reason' => 'From the file.']]], 'locked'],
    'a short reason' => ['salts', 'ok', [], 'reason'],
    'nothing open' => ['debug-mode', 'A long enough reason.', [], 'not_open'],
]);

it('sends a test to the channel that is named, and to no other', function (bool $accepted, string $status): void {
    $sent = [];
    Functions\when('wp_mail')->alias(function (array $to) use (&$sent, $accepted): bool {
        $sent[] = $to;

        return $accepted;
    });
    $backend = $this->admin->backend(['notifications' => ['channels' => ['team' => ['type' => 'mail', 'to' => 'ops@example.com']]]]);

    expect($backend->testChannel('other'))->toBeNull()->and($sent)->toBe([]);

    $result = $backend->testChannel('team');

    expect($result['status'])->toBe($status)->and($sent)->toBe([['ops@example.com']]);
})->with([[true, 'sent'], [false, 'failed']]);

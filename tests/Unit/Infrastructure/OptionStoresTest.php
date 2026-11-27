<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\QueuedDelivery;
use Pollora\Hornet\Domain\Scan\Baseline;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Domain\Scan\ScanState;
use Pollora\Hornet\Infrastructure\WordPress\OptionBaselineStore;
use Pollora\Hornet\Infrastructure\WordPress\OptionNotificationState;
use Pollora\Hornet\Infrastructure\WordPress\OptionRetryQueue;
use Pollora\Hornet\Infrastructure\WordPress\OptionRunLock;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStateStore;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStore;
use Pollora\Hornet\Infrastructure\WordPress\OptionSetAsideStore;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\FakeWpdb;
use Pollora\Hornet\Tests\Fixtures\Samples;

function utc(string $time): DateTimeImmutable
{
    return new DateTimeImmutable($time, new DateTimeZone('UTC'));
}

beforeEach(function (): void {
    $this->site = FakeSite::install();
});

dataset('damaged JSON', [
    'nothing stored' => [null],
    'an empty string' => [''],
    'not a string' => [['already' => 'an array']],
    'cut in the middle' => ['{"a":'],
    'a number' => ['5'],
    'a word' => ['true'],
    'null' => ['null'],
    'a string' => ['"text"'],
]);

describe('the last scan', function (): void {
    function snapshot(): ScanSnapshot
    {
        return new ScanSnapshot(utc('2026-11-24 10:00:00'), ['core-integrity' => Finding::fail('core-integrity', Severity::High, 'A file differs.', ['wp-load.php'])], [], ['suspicious-files']);
    }

    it('is read back as it was saved, from an option that is not loaded on every page', function (): void {
        (new OptionScanStore)->save(snapshot());
        $last = (new OptionScanStore)->last();

        expect($last?->toArray())->toBe(snapshot()->toArray())
            ->and($this->site->autoload['hornet_scan'])->toBeFalse()
            ->and($this->site->options['hornet_scan'])->toBeString()
            ->and($this->site->networkOptions)->toBe([]);
    });

    it('replaces the scan before it', function (): void {
        (new OptionScanStore)->save(snapshot());
        (new OptionScanStore)->save(new ScanSnapshot(utc('2026-11-25 10:00:00'), []));

        expect((new OptionScanStore)->last()?->at->format('Y-m-d'))->toBe('2026-11-25');
    });

    it('is kept for the whole network on a multisite, where the files are shared', function (): void {
        $this->site->multisite = true;
        (new OptionScanStore)->save(snapshot());

        expect($this->site->options)->toBe([])
            ->and($this->site->networkOptions)->toHaveKey('hornet_scan')
            ->and((new OptionScanStore)->last()?->unfinished)->toBe(['suspicious-files']);
    });

    it('is none when what is stored cannot be read', function (mixed $stored): void {
        if ($stored !== null) {
            $this->site->options['hornet_scan'] = $stored;
        }

        expect((new OptionScanStore)->last())->toBeNull();
    })->with('damaged JSON');
});

describe('where a scan is', function (): void {
    it('is read back as it was saved', function (): void {
        (new OptionScanStateStore)->save(new ScanState(ScanState::RUNNING, 'core-integrity', 1000));

        expect((new OptionScanStateStore)->get()->toArray())->toBe(['status' => 'running', 'task' => 'core-integrity', 'since' => 1000])
            ->and($this->site->autoload['hornet_scan_state'])->toBeFalse();
    });

    it('follows the later of two saves, and belongs to the network on a multisite', function (bool $multisite): void {
        $this->site->multisite = $multisite;
        (new OptionScanStateStore)->save(new ScanState(ScanState::QUEUED, null, 1000));
        (new OptionScanStateStore)->save(new ScanState(ScanState::RUNNING, 'x', 2000));

        expect((new OptionScanStateStore)->get()->since)->toBe(2000)
            ->and(array_key_exists('hornet_scan_state', $multisite ? $this->site->networkOptions : $this->site->options))->toBeTrue()
            ->and($multisite ? $this->site->options : $this->site->networkOptions)->toBe([]);
    })->with([false, true]);

    it('is idle when what is stored cannot be read', function (mixed $stored): void {
        if ($stored !== null) {
            $this->site->options['hornet_scan_state'] = $stored;
        }

        expect((new OptionScanStateStore)->get()->toArray())->toBe(ScanState::idle()->toArray());
    })->with('damaged JSON');
});

describe('the baselines of unknown plugins', function (): void {
    it('are read back by slug, the themes apart from the plugins', function (bool $multisite): void {
        $this->site->multisite = $multisite;
        (new OptionBaselineStore)->save(['premium' => new Baseline('1.2', ['a.php' => 'hash-a'])]);
        (new OptionBaselineStore(OptionBaselineStore::THEMES))->save(['premium' => new Baseline('3.0', ['style.css' => 'hash-b'])]);

        expect((new OptionBaselineStore)->load()['premium']->toArray())->toBe(['version' => '1.2', 'hashes' => ['a.php' => 'hash-a']])
            ->and((new OptionBaselineStore(OptionBaselineStore::THEMES))->load()['premium']->version)->toBe('3.0')
            ->and($multisite ? $this->site->options : $this->site->networkOptions)->toBe([])
            ->and($multisite ? [] : $this->site->autoload)->toBe($multisite ? [] : ['hornet_baseline' => false, 'hornet_baseline_themes' => false]);
    })->with([false, true]);

    it('are written over by a later look', function (): void {
        (new OptionBaselineStore)->save(['premium' => new Baseline('1.2', [])]);
        (new OptionBaselineStore)->save(['premium' => new Baseline('1.3', [])]);

        expect((new OptionBaselineStore)->load()['premium']->version)->toBe('1.3');
    });

    it('are empty when what is stored cannot be read', function (mixed $stored): void {
        if ($stored !== null) {
            $this->site->options['hornet_baseline'] = $stored;
        }

        expect((new OptionBaselineStore)->load())->toBe([]);
    })->with('damaged JSON');

    it('leave out an entry that is damaged, and keep the others', function (): void {
        $this->site->options['hornet_baseline'] = '{"bad":{"version":1},"good":{"version":"2","hashes":{}}}';

        expect(array_keys((new OptionBaselineStore)->load()))->toBe(['good']);
    });
});

describe('the findings set aside from the screen', function (): void {
    it('are kept by check, with who and why, in an option that is not loaded on every page', function (): void {
        $store = new OptionSetAsideStore;
        $store->put(new SetAside('https', 'Behind a proxy.', 3, 'ana', 100));
        $store->put(new SetAside('salts', 'Rotated elsewhere.', 4, 'bo', 200));

        expect(array_keys($store->all()))->toBe(['https', 'salts'])
            ->and($store->all()['https']->toArray())->toBe(['reason' => 'Behind a proxy.', 'user' => 3, 'login' => 'ana', 'at' => 100])
            ->and($this->site->autoload['hornet_set_aside'])->toBeFalse();
    });

    it('forget one without touching the others', function (): void {
        $store = new OptionSetAsideStore;
        $store->put(new SetAside('https', 'Behind a proxy.', 3, 'ana', 100));
        $store->put(new SetAside('salts', 'Rotated elsewhere.', 4, 'bo', 200));
        $store->remove('https');

        expect(array_keys($store->all()))->toBe(['salts']);
    });

    it('set nothing aside when what is stored cannot be read: a finding must not hide on damaged data', function (mixed $stored): void {
        if ($stored !== null) {
            $this->site->options['hornet_set_aside'] = $stored;
        }

        expect((new OptionSetAsideStore)->all())->toBe([]);
    })->with('damaged JSON');

    it('leave out an entry with no reason', function (): void {
        $this->site->options['hornet_set_aside'] = '{"https":{"user":1,"login":"a","at":1},"salts":{"reason":"ok","user":1,"login":"a","at":1}}';

        expect(array_keys((new OptionSetAsideStore)->all()))->toBe(['salts']);
    });
});

describe('what each channel was told', function (): void {
    it('remembers when a channel heard an alert, and gives it back in UTC', function (): void {
        $state = new OptionNotificationState;
        $state->remember('team', ['a', 'b'], utc('2026-11-25 10:00:00'));

        expect($state->lastTold('team', 'a')?->format('c'))->toBe('2026-11-25T10:00:00+00:00')
            ->and($state->lastTold('team', 'b'))->not->toBeNull()
            ->and($state->lastTold('team', 'c'))->toBeNull()
            ->and($state->lastTold('other', 'a'))->toBeNull()
            ->and($this->site->autoload['hornet_notify_state'])->toBeFalse();
    });

    it('forgets only the alerts it is told to', function (): void {
        $state = new OptionNotificationState;
        $state->remember('team', ['a', 'b'], utc('now'));
        $state->forget('team', ['a']);

        expect($state->lastTold('team', 'a'))->toBeNull()->and($state->lastTold('team', 'b'))->not->toBeNull();
    });

    it('drops what was told more than sixty days before the latest write', function (): void {
        $state = new OptionNotificationState;
        $state->remember('team', ['old'], utc('2026-09-01'));
        $state->remember('team', ['edge'], utc('2026-09-26'));
        $state->remember('team', ['new'], utc('2026-11-25'));

        expect($state->lastTold('team', 'old'))->toBeNull()
            ->and($state->lastTold('team', 'edge'))->not->toBeNull()
            ->and($state->lastTold('team', 'new'))->not->toBeNull();
    });

    it('counts the messages sent since a moment, per channel, and keeps an hour of them', function (): void {
        $state = new OptionNotificationState;
        $state->recordSent('team', utc('2026-11-25 08:00:00'));
        $state->recordSent('team', utc('2026-11-25 10:00:00'));
        $state->recordSent('team', utc('2026-11-25 10:30:00'));
        $state->recordSent('other', utc('2026-11-25 10:30:00'));

        expect($state->sentSince('team', utc('2026-11-25 00:00:00')))->toBe(2)
            ->and($state->sentSince('team', utc('2026-11-25 10:30:00')))->toBe(1)
            ->and($state->sentSince('team', utc('2026-11-25 10:30:01')))->toBe(0)
            ->and($state->sentSince('nobody', utc('2026-11-25 00:00:00')))->toBe(0);
    });

    it('reads nothing from a damaged option, and does not fail', function (mixed $stored): void {
        $this->site->options['hornet_notify_state'] = $stored;
        $state = new OptionNotificationState;

        expect($state->lastTold('team', 'a'))->toBeNull()->and($state->sentSince('team', utc('2000-01-01')))->toBe(0);
    })->with([
        'a string' => ['nonsense'],
        'wrong types inside' => [['told' => ['team' => 'x'], 'sent' => ['team' => 'y']]],
        'times that are text' => [['told' => ['team' => ['a' => '1']], 'sent' => ['team' => ['1', null]]]],
        'not arrays at all' => [['told' => 3, 'sent' => false]],
    ]);
});

describe('the lock of a run', function (): void {
    it('is held by one run at a time, and free again when released', function (): void {
        $lock = new OptionRunLock;

        expect($lock->acquire())->toBeTrue()
            ->and((new OptionRunLock)->acquire())->toBeFalse()
            ->and($this->site->autoload['hornet_notify_lock'])->toBeFalse();

        $lock->release();

        expect((new OptionRunLock)->acquire())->toBeTrue();
    });

    it('gives each job its own lock', function (): void {
        expect((new OptionRunLock)->acquire())->toBeTrue()
            ->and((new OptionRunLock('hornet_scan_lock'))->acquire())->toBeTrue()
            ->and(array_keys($this->site->options))->toBe(['hornet_notify_lock', 'hornet_scan_lock']);
    });

    it('takes over the lock of a run that died more than ten minutes ago, as the database gives it back: in text', function (bool $likeTheDatabase): void {
        $this->site->likeTheDatabase = $likeTheDatabase;
        $this->site->options['hornet_notify_lock'] = time() - 700;

        expect((new OptionRunLock)->acquire())->toBeTrue()
            ->and((int) $this->site->options['hornet_notify_lock'])->toBeGreaterThanOrEqual(time() - 5);
    })->with(['the same request' => [false], 'a later request' => [true]]);

    it('leaves a lock that is recent, or that cannot be read as a time', function (mixed $held): void {
        expect($held)->not->toBeInstanceOf(Closure::class);
        $this->site->likeTheDatabase = true;
        $this->site->options['hornet_notify_lock'] = $held;

        expect((new OptionRunLock)->acquire())->toBeFalse()->and($this->site->options['hornet_notify_lock'])->toBe($held);
    })->with([
        'a minute old' => [time() - 60],
        'not a number' => ['running'],
        'nothing at all' => [''],
    ]);
});

describe('the messages that wait to be sent again', function (): void {
    function delivery(string $id = '0123456789abcdef', string $due = '2026-11-25 10:00:00', int $attempts = 2): QueuedDelivery
    {
        return new QueuedDelivery($id, 'team', Samples::notification(), $attempts, utc($due));
    }

    beforeEach(function (): void {
        $this->db = $GLOBALS['wpdb'] = new FakeWpdb;
    });

    /**
     * What the database answers: the rows of the queue's options.
     */
    function queued(FakeSite $site): array
    {
        $rows = [];

        foreach ($site->options as $name => $value) {
            if (str_starts_with($name, 'hornet_nq_')) {
                $rows[] = ['option_name' => $name, 'option_value' => $value];
            }
        }

        return $rows;
    }

    it('keeps one option per message, not loaded on every page, and gives back those that are due', function (): void {
        $queue = new OptionRetryQueue;
        $queue->push(delivery());
        $this->db->answers = [queued($this->site)];

        $due = $queue->due(utc('2026-11-25 10:00:00'));

        expect($due)->toHaveCount(1)
            ->and($due[0]->id)->toBe('0123456789abcdef')
            ->and($due[0]->channel)->toBe('team')
            ->and($due[0]->attempts)->toBe(2)
            ->and($due[0]->dueAt->format('c'))->toBe('2026-11-25T10:00:00+00:00')
            ->and($due[0]->notification->toArray())->toBe(Samples::notification()->toArray())
            ->and($this->site->autoload['hornet_nq_0123456789abcdef'])->toBeFalse();
    });

    it('does not give back a message before its time, and keeps it', function (): void {
        $queue = new OptionRetryQueue;
        $queue->push(delivery());
        $this->db->answers = [queued($this->site)];

        expect($queue->due(utc('2026-11-25 09:59:59')))->toBe([])->and($this->site->options)->toHaveCount(1);
    });

    it('asks the options table for its own rows only, oldest first and bounded', function (): void {
        $this->db->answers = [[]];
        (new OptionRetryQueue)->count();

        expect($this->db->ran[0])->toBe("SELECT option_name, option_value FROM `wp_options` WHERE option_name LIKE 'hornet\\\\_nq\\\\_%' ORDER BY option_id ASC LIMIT 150");
    });

    it('refuses an identifier that is not its own, for writing and for removing', function (string $id): void {
        $this->site->options['hornet_nq_'.$id] = 'kept';
        $queue = new OptionRetryQueue;
        $this->db->answers = [[]];
        $queue->push(delivery($id));
        $queue->remove($id);

        expect($this->site->options)->toBe(['hornet_nq_'.$id => 'kept']);
    })->with(['too short' => ['abc'], 'a path' => ['../../0123456789'], 'capitals' => ['0123456789ABCDEF'], 'a newline after' => ["0123456789abcdef\n"]]);

    it('removes a message that went through', function (): void {
        $queue = new OptionRetryQueue;
        $queue->push(delivery());
        $queue->remove('0123456789abcdef');

        expect($this->site->options)->toBe([]);
    });

    it('takes no new message when a hundred wait, but still updates one of them', function (): void {
        $rows = array_map(static fn (int $i): array => ['option_name' => sprintf('hornet_nq_%016x', $i), 'option_value' => '{}'], range(1, 100));
        $this->db->answers = [$rows, $rows];
        $this->site->options[sprintf('hornet_nq_%016x', 1)] = '{}';
        $queue = new OptionRetryQueue;

        $queue->push(delivery(sprintf('%016x', 500)));
        $queue->push(delivery(sprintf('%016x', 1)));

        expect($this->site->options)->toHaveCount(1)
            ->and($this->site->options[sprintf('hornet_nq_%016x', 1)])->toContain('"channel":"team"');
    });

    it('takes the hundredth message', function (): void {
        $this->db->answers = [array_map(static fn (int $i): array => ['option_name' => sprintf('hornet_nq_%016x', $i), 'option_value' => '{}'], range(1, 99))];
        (new OptionRetryQueue)->push(delivery());

        expect($this->site->options)->toHaveKey('hornet_nq_0123456789abcdef');
    });

    it('deletes a row that is damaged or not its own, rather than meet it at every run', function (string $name, string $value): void {
        $this->site->options[$name] = $value;
        $this->db->answers = [[['option_name' => $name, 'option_value' => $value]]];

        expect((new OptionRetryQueue)->due(utc('2030-01-01')))->toBe([])->and($this->site->options)->toBe([]);
    })->with(function (): array {
        $good = ['channel' => 'team', 'attempts' => 1, 'due' => 1, 'notification' => Samples::notification()->toArray()];
        $name = 'hornet_nq_0123456789abcdef';
        $without = static fn (array $change): array => [$name, (string) json_encode([...$good, ...$change])];

        return [
            'not JSON' => [$name, 'nonsense'],
            'a foreign name' => ['hornet_nq_other', (string) json_encode($good)],
            'no channel' => $without(['channel' => 5]),
            'attempts as text' => $without(['attempts' => '1']),
            'no due time' => $without(['due' => null]),
            'no notification' => $without(['notification' => 'x']),
            'a notification with no alert' => $without(['notification' => ['id' => 'x', 'alerts' => [], 'created_at' => 'now']]),
            'a creation date that is not a date' => $without(['notification' => [...Samples::notification()->toArray(), 'created_at' => 'not a date']]),
        ];
    });

    it('counts the rows that have a name and a value, and none when the query fails', function (): void {
        $this->db->answers = [[['option_name' => 'hornet_nq_a', 'option_value' => '1'], ['option_name' => 'hornet_nq_b'], 'x'], null];
        $queue = new OptionRetryQueue;

        expect($queue->count())->toBe(1)->and($queue->count())->toBe(0);
    });
});

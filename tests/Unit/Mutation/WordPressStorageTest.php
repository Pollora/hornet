<?php

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Application\Notification\NotificationService;
use Pollora\Hornet\Application\Notification\Notifier;
use Pollora\Hornet\Application\Scan\ScanJob;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Application\Scan\ScanTask;
use Pollora\Hornet\Application\Scan\TaskResult;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\JournalQuery;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Domain\Notification\Backoff;
use Pollora\Hornet\Domain\Notification\Delivery;
use Pollora\Hornet\Domain\Scan\Baseline;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\PluginListing;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Infrastructure\WordPress\ErrorDisplayNote;
use Pollora\Hornet\Infrastructure\WordPress\I18n\WpTranslator;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalPrivacy;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TableJournal;
use Pollora\Hornet\Infrastructure\WordPress\LoginDates;
use Pollora\Hornet\Infrastructure\WordPress\LoginDatesModule;
use Pollora\Hornet\Infrastructure\WordPress\LoginThrottleModule;
use Pollora\Hornet\Infrastructure\WordPress\NotificationModule;
use Pollora\Hornet\Infrastructure\WordPress\OptionBaselineStore;
use Pollora\Hornet\Infrastructure\WordPress\OptionNotificationState;
use Pollora\Hornet\Infrastructure\WordPress\OptionRetryQueue;
use Pollora\Hornet\Infrastructure\WordPress\OptionRunLock;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStore;
use Pollora\Hornet\Infrastructure\WordPress\OptionSetAsideStore;
use Pollora\Hornet\Infrastructure\WordPress\ScanModule;
use Pollora\Hornet\Infrastructure\WordPress\ThrottleProblemNote;
use Pollora\Hornet\Infrastructure\WordPress\TransientAdvisoryCache;
use Pollora\Hornet\Infrastructure\WordPress\TransientPluginListingCache;
use Pollora\Hornet\Tests\Fixtures\ArrayLock;
use Pollora\Hornet\Tests\Fixtures\FakeChannel;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\FakeWpdb;
use Pollora\Hornet\Tests\Fixtures\FakeWpUser;
use Pollora\Hornet\Tests\Fixtures\InMemoryJournal;
use Pollora\Hornet\Tests\Fixtures\InMemoryNotificationState;
use Pollora\Hornet\Tests\Fixtures\InMemoryRetryQueue;
use Pollora\Hornet\Tests\Fixtures\InMemoryScanStateStore;
use Pollora\Hornet\Tests\Fixtures\InMemoryScanStore;
use Pollora\Hornet\Tests\Fixtures\Samples;

beforeEach(function (): void {
    $this->site = FakeSite::install();
    $this->db = $GLOBALS['wpdb'] = new FakeWpdb;
});

/** Runs again when the second changes while it runs: what is measured is relative to "now". */
function mutWpSameSecond(Closure $act): mixed
{
    $tries = 0;

    do {
        $now = time();
        $result = $act($now);
    } while (time() !== $now && ++$tries < 5);

    return $result;
}

it('writes the addresses and paths it stores as they are, not escaped', function (): void {
    (new OptionBaselineStore)->save(['a' => new Baseline('1', ['dir/file.php' => 'h'])]);
    (new OptionScanStore)->save(new ScanSnapshot(new DateTimeImmutable('@1'), ['x' => Finding::pass('x', 'a/b')]));
    (new OptionSetAsideStore)->put(new SetAside('x', 'See https://example.com/why', 1, 'a', 1));

    expect($this->site->options['hornet_baseline'])->toContain('dir/file.php')
        ->and($this->site->options['hornet_scan'])->toContain('a/b')
        ->and($this->site->options['hornet_set_aside'])->toContain('https://example.com/why');
});

it('takes a lock back only after more than ten minutes, and does not load it with every page', function (int $age, bool $taken): void {
    $result = mutWpSameSecond(function (int $now) use ($age): bool {
        $this->site->options['hornet_notify_lock'] = $now - $age;
        $this->site->autoload = [];

        return (new OptionRunLock)->acquire();
    });

    expect($result)->toBe($taken)->and($this->site->autoload['hornet_notify_lock'] ?? false)->toBeFalse();
})->with([[600, false], [601, true]]);

it('keeps what was sent for exactly an hour, as a list', function (): void {
    $state = new OptionNotificationState;
    $state->recordSent('c', new DateTimeImmutable('@1000'));
    $state->recordSent('c', new DateTimeImmutable('@2000'));
    $state->recordSent('c', new DateTimeImmutable('@5600'));

    expect($this->site->options['hornet_notify_state']['sent']['c'])->toBe([2000, 5600]);

    $this->site->options['hornet_notify_state']['sent']['c'] = ['x', 5600];
    $state->recordSent('c', new DateTimeImmutable('@5601'));

    expect($this->site->options['hornet_notify_state']['sent']['c'])->toBe([5600, 5601]);
});

it('goes on past a damaged waiting message to the next one', function (): void {
    $good = ['channel' => 'c', 'attempts' => 1, 'due' => 10, 'notification' => Samples::notification()->toArray()];
    $this->db->answers = [[
        ['option_name' => 'hornet_nq_bad', 'option_value' => '{}'],
        'not a row',
        ['option_name' => 'hornet_nq_0123456789abcdef', 'option_value' => (string) json_encode($good)],
    ]];
    $deleted = [];
    Functions\when('delete_option')->alias(function (string $name) use (&$deleted): bool {
        $deleted[] = $name;

        return true;
    });

    expect((new OptionRetryQueue)->due(new DateTimeImmutable('@100')))->toHaveCount(1)
        ->and($deleted)->toBe(['hornet_nq_bad']);
});

it('writes the same problem of the throttle again after an hour, and reads it for a week', function (int $age, bool $rewritten): void {
    $note = new ThrottleProblemNote;

    $written = mutWpSameSecond(function (int $now) use ($note, $age): bool {
        $this->site->options[LoginThrottleModule::PROBLEM_OPTION] = ['reason' => 'shared', 'at' => $now - $age];
        $note->record('shared');

        return $this->site->options[LoginThrottleModule::PROBLEM_OPTION]['at'] === $now;
    });

    expect($written)->toBe($rewritten);
})->with([[3599, false], [3600, true]]);

it('still reads a note that is exactly a week old, and none without a date', function (): void {
    $note = new ThrottleProblemNote;

    $read = mutWpSameSecond(function (int $now) use ($note): ?string {
        $this->site->options[LoginThrottleModule::PROBLEM_OPTION] = ['reason' => 'shared', 'at' => $now - 604800];

        return $note->read();
    });

    $this->site->options[LoginThrottleModule::PROBLEM_OPTION] = ['reason' => 'shared'];

    expect($read)->toBe('shared')->and($note->read())->toBeNull();
});

it('reads every way PHP says it shows its errors', function (string $setting, string $seen): void {
    (new ErrorDisplayNote)->record($setting);

    expect($this->site->networkOptions[ErrorDisplayNote::OPTION])->toBe($seen);
})->with([['yes', 'on'], ['TRUE', 'on'], ['stdout', 'on'], [' On ', 'on'], ['stderr', 'off'], ['0', 'off']]);

it('keeps a plugin listing and an advisory whose text is not valid UTF-8', function (): void {
    (new TransientPluginListingCache)->put('a', 10, PluginListing::listed("Bad \xB1 name", '1', 1));
    (new TransientAdvisoryCache)->put('k', ['text' => "Bad \xB1"], 60);

    expect((new TransientPluginListingCache)->get('a')['at'])->toBe(10)
        ->and((new TransientAdvisoryCache)->get('k'))->toBe(['text' => "Bad \u{FFFD}"])
        ->and(array_keys($this->site->siteTransients))->toBe(['hornet_wporg_'.md5('a'), 'hornet_osv_'.md5('k')]);
});

it('notes since when logins are noted with the first one, and one second after the start of time is a date', function (): void {
    $dates = new LoginDates;
    $dates->note(4);

    expect($this->site->networkOptions)->toHaveKey(LoginDates::SINCE);

    $this->site->networkOptions[LoginDates::SINCE] = '1';
    $this->site->userMeta[4][LoginDates::META] = '1';

    expect($dates->since())->toBe(1)->and($dates->last(4))->toBe(1);

    $asked = null;
    Functions\when('get_user_meta')->alias(function (mixed ...$arguments) use (&$asked): string {
        $asked = $arguments;

        return '';
    });
    $dates->last(4);

    expect($asked)->toBe([4, LoginDates::META, true]);
});

it('names itself in the privacy tools, and exports the last login of a person', function (): void {
    Functions\when('get_user_by')->alias(static fn (string $field, string $email): mixed => $email === 'a@example.com' ? FakeWpUser::named(4, 'a') : false);
    $this->site->userMeta[4][LoginDates::META] = 0 + 86400;
    $module = new LoginDatesModule(new LoginDates);
    $journal = new JournalPrivacy(new InMemoryJournal, 30);
    $exporters = $module->exporters(['other' => 1]);

    expect($exporters['hornet-last-login']['exporter_friendly_name'])->toBe('Last login (Hornet)')
        ->and($module->erasers(null)['hornet-last-login']['eraser_friendly_name'])->toBe('Last login (Hornet)')
        ->and($journal->exporters(null)['hornet-journal']['exporter_friendly_name'])->toBe('Security journal (Hornet)')
        ->and($journal->erasers(['other' => 1]))->toHaveKeys(['other', 'hornet-journal'])
        ->and($journal->erasers(['other' => 1])['hornet-journal']['eraser_friendly_name'])->toBe('Security journal (Hornet)')
        ->and($journal->erasers('x'))->toHaveKeys(['hornet-journal'])
        ->and($module->export('a@example.com'))->toBe(['data' => [['group_id' => 'hornet-last-login', 'group_label' => 'Last login', 'item_id' => 'hornet-last-login-4', 'data' => [['name' => 'When', 'value' => '1970-01-02 00:00:00 UTC']]]], 'done' => true])
        ->and($module->export('b@example.com'))->toBe(['data' => [], 'done' => true]);
});

it('numbers an event that has no number 0 in an export', function (): void {
    Functions\when('get_user_by')->justReturn(FakeWpUser::named(4, 'a'));
    $journal = new InMemoryJournal;
    $journal->record(new Event(new DateTimeImmutable('@1'), 'login.success', Source::Web, 4, 'a', 'user:4', '', []));

    expect((new JournalPrivacy($journal, 30))->export('a@example.com')['data'][0]['item_id'])->toBe('hornet-journal-0');
});

it('reads only the lines of the journal it can make sense of, as a list, with details of a few levels', function (): void {
    $row = static fn (string $details): array => ['occurred_at' => '2026-01-01 00:00:00', 'type' => 'login.success', 'source' => 'web', 'details' => $details];
    $this->db->answers = [['not a row', ['source' => 'nowhere'], $row('{"ok":"yes","deep":[[1]]}'), $row('{"ok":"yes","deeper":[[[1]]]}')]];

    $events = (new TableJournal)->find(new JournalQuery);

    expect(array_keys($events))->toBe([0, 1])
        ->and($events[0]->details['ok'] ?? null)->toBe('yes')
        ->and($events[1]->details)->toBe([]);
});

it('asks WordPress for the words of the package, in its own domain', function (): void {
    $asked = null;
    Functions\when('translate_nooped_plural')->alias(function (mixed ...$arguments) use (&$asked): string {
        $asked = $arguments;

        return 'translated';
    });
    Functions\when('did_action')->justReturn(0);

    expect((new WpTranslator('/nowhere'))->plural('one', 'many', 3))->toBe('translated')
        ->and($asked)->toBe([['singular' => 'one', 'plural' => 'many', 'domain' => 'pollora-hornet', 'context' => null], 3, 'pollora-hornet']);
});

it('lets the other language go before it loads one', function (): void {
    $unloaded = [];
    Functions\when('did_action')->justReturn(1);
    Functions\when('determine_locale')->justReturn('xx_'.bin2hex(random_bytes(3)));
    Functions\when('unload_textdomain')->alias(function (mixed ...$arguments) use (&$unloaded): bool {
        $unloaded[] = $arguments;

        return true;
    });
    Functions\when('translate')->returnArg(1);

    (new WpTranslator('/nowhere'))->translate('word');

    expect($unloaded)->toBe([['pollora-hornet', true]]);
});

function mutWpScanJob(bool $unfinished, array $left = [], ?ArrayLock $lock = null): array
{
    $runs = new ArrayObject;
    $task = new class($unfinished, $runs) implements ScanTask
    {
        public function __construct(private readonly bool $unfinished, private readonly ArrayObject $runs) {}

        public function id(): string
        {
            return 'core-integrity';
        }

        public function run(?Pins $pinned, bool $repin): TaskResult
        {
            $this->runs[] = true;

            return new TaskResult(Finding::pass('core-integrity', 'fine'), null, $this->unfinished);
        }
    };
    $job = new ScanJob(static fn (): Scanner => new Scanner([$task], new InMemoryScanStore, new FakeClock), $lock ?? new ArrayLock, new InMemoryScanStateStore, new FakeClock, static fn (): array => $left);

    return [new ScanModule($job, 'daily'), $runs];
}

it('schedules the scan ten minutes and the audit five minutes from now, to the second', function (string $module, int $delay): void {
    $at = null;
    Functions\when('wp_get_schedule')->justReturn(false);
    Functions\when('wp_clear_scheduled_hook')->justReturn(0);
    Functions\when('wp_schedule_event')->alias(function (int $when) use (&$at): bool {
        $at = $when;

        return true;
    });
    $subject = $module === 'scan' ? mutWpScanJob(false)[0] : new NotificationModule(static fn (): never => throw new LogicException, 'daily');

    $offset = mutWpSameSecond(function (int $now) use ($subject, &$at): int {
        $subject->plan();

        return $at - $now;
    });

    expect($offset)->toBe($delay);
})->with([['scan', 600], ['audit', 300]]);

it('tells that a scan was completed, and goes on only with what is left', function (): void {
    Functions\when('wp_next_scheduled')->justReturn(false);
    Actions\expectDone('pollora/hornet/scan_completed')->once();
    [$module, $runs] = mutWpScanJob(false, ['core-integrity']);

    $module->continue();

    expect($runs)->toHaveCount(1);
});

it('continues nothing when nothing is left, and tells nothing of a run that stepped aside', function (): void {
    Actions\expectDone('pollora/hornet/scan_completed')->never();
    [$idle, $runs] = mutWpScanJob(false);
    $idle->continue();
    $lock = new ArrayLock;
    $lock->acquire();
    [$locked] = mutWpScanJob(false, [], $lock);
    $locked->scan();

    expect($runs)->toHaveCount(0);
});

it('tries the waiting messages again, and plans the next try only when some still wait', function (): void {
    $queue = new InMemoryRetryQueue;
    $channel = new FakeChannel;
    $clock = new FakeClock;
    $service = new NotificationService(
        new Auditor(DefaultChecks::all()),
        new FakeEnvironment(xmlRpc: true),
        new Notifier([$channel], new InMemoryNotificationState, $queue, $clock, new Backoff, 86400, 20, static fn (): string => 'abcdef0123456789'),
        new ArrayLock,
        $queue,
        'S',
        'u',
        [],
    );
    $planned = 0;
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\when('wp_schedule_single_event')->alias(function () use (&$planned): bool {
        $planned++;

        return true;
    });
    $module = new NotificationModule(static fn (): NotificationService => $service, 'daily');

    $module->retry();

    expect($planned)->toBe(0);

    $channel->answers = [Delivery::retry('down'), Delivery::retry('still down')];
    $service->run();
    $clock->advance('P2D');
    $module->retry();

    expect($channel->sent)->toHaveCount(2)->and($planned)->toBe(1);
});

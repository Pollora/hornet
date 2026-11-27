<?php

declare(strict_types=1);

use Pollora\Hornet\Application\AlertFactory;
use Pollora\Hornet\Application\Audit\SetAsideService;
use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\Journal\Decision;
use Pollora\Hornet\Application\Journal\FloodGuard;
use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Application\Login\Unlocker;
use Pollora\Hornet\Application\Notification\ChannelStatus;
use Pollora\Hornet\Application\Notification\NotificationService;
use Pollora\Hornet\Application\Notification\Notifier;
use Pollora\Hornet\Application\Scan\CoreIntegrityTask;
use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\SetAsideException;
use Pollora\Hornet\Domain\Exceptions\UnknownCheckException;
use Pollora\Hornet\Domain\Journal\EventTypes;
use Pollora\Hornet\Domain\Journal\WindowCounter;
use Pollora\Hornet\Domain\Login\AttemptStore;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Domain\Notification\Backoff;
use Pollora\Hornet\Domain\Notification\Delivery;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Domain\Notification\QueuedDelivery;
use Pollora\Hornet\Tests\Fixtures\ArrayFileSystem;
use Pollora\Hornet\Tests\Fixtures\ArrayLock;
use Pollora\Hornet\Tests\Fixtures\FakeChannel;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeCoreChecksums;
use Pollora\Hornet\Tests\Fixtures\FakeRequestContext;
use Pollora\Hornet\Tests\Fixtures\InMemoryJournal;
use Pollora\Hornet\Tests\Fixtures\InMemoryNotificationState;
use Pollora\Hornet\Tests\Fixtures\InMemoryRetryQueue;
use Pollora\Hornet\Tests\Fixtures\InMemorySetAsideStore;
use Pollora\Hornet\Tests\Fixtures\InMemoryWindowCounter;
use Pollora\Hornet\Tests\Fixtures\Samples;

// Notifier: timing, limits and what is reported

/**
 * @param  list<FakeChannel>  $channels
 * @return array{Notifier, InMemoryNotificationState, InMemoryRetryQueue, FakeClock}
 */
function mutAppNotifier(array $channels, int $cooldown = 3600, int $rateLimit = 20): array
{
    $state = new InMemoryNotificationState;
    $queue = new InMemoryRetryQueue;
    $clock = new FakeClock;
    $counter = 0;

    return [new Notifier($channels, $state, $queue, $clock, new Backoff, $cooldown, $rateLimit, static function () use (&$counter): string {
        return sprintf('%016x', ++$counter);
    }), $state, $queue, $clock];
}

it('tells an alert again at the very end of its cooldown', function (): void {
    $channel = new FakeChannel;
    [$notifier, , , $clock] = mutAppNotifier([$channel]);

    $notifier->dispatch([Samples::alert('a')], 'S', 'u');
    $clock->advance('PT59M59S');
    $before = $notifier->dispatch([Samples::alert('a')], 'S', 'u');
    $clock->advance('PT1S');
    $at = $notifier->dispatch([Samples::alert('a')], 'S', 'u');

    expect($before[0]->status)->toBe(ChannelStatus::Nothing)->and($at[0]->status)->toBe(ChannelStatus::Sent);
});

it('counts the messages of the last hour only against the limit, and says why one waits', function (): void {
    [$notifier, , , $clock] = mutAppNotifier([new FakeChannel], 3600, 1);

    $notifier->dispatch([Samples::alert('a')], 'S', 'u');
    $clock->advance('PT59M');
    $limited = $notifier->dispatch([Samples::alert('b'), Samples::alert('c')], 'S', 'u');
    $clock->advance('PT2M');
    $free = $notifier->dispatch([Samples::alert('b')], 'S', 'u');

    expect($limited[0]->status)->toBe(ChannelStatus::RateLimited)
        ->and($limited[0]->alerts)->toBe(2)
        ->and($limited[0]->reason)->toBe('The channel got its share of messages for this hour.')
        ->and($free[0]->status)->toBe(ChannelStatus::Sent);
});

it('queues a failure that can pass for the delay asked of it, and does not tell it twice meanwhile', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::retry('busy', 600)];
    [$notifier, $state, $queue, $clock] = mutAppNotifier([$channel]);

    $outcome = $notifier->dispatch([Samples::alert('a')], 'S', 'u')[0];

    expect($outcome->status)->toBe(ChannelStatus::Queued)->and($outcome->alerts)->toBe(1)->and($outcome->reason)->toBe('busy')
        ->and(array_values($queue->items)[0]->attempts)->toBe(1)
        ->and(array_values($queue->items)[0]->dueAt)->toEqual($clock->now()->add(new DateInterval('PT600S')))
        ->and($state->told['chat'])->toHaveKey('a')
        ->and($notifier->dispatch([Samples::alert('a')], 'S', 'u')[0]->status)->toBe(ChannelStatus::Nothing);
});

it('reports a failure that cannot pass, queues nothing and remembers nothing', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::failed('revoked')];
    [$notifier, $state, $queue] = mutAppNotifier([$channel]);

    $outcome = $notifier->dispatch([Samples::alert('a')], 'S', 'u')[0];

    expect($outcome->status)->toBe(ChannelStatus::Failed)->and($outcome->alerts)->toBe(1)->and($outcome->reason)->toBe('revoked')
        ->and($queue->count())->toBe(0)->and($state->told)->toBe([]);
});

it('tells a channel that has nothing due apart from the others', function (): void {
    $quiet = new FakeChannel('quiet', Severity::Critical);
    $loud = new FakeChannel('loud', Severity::Info);
    [$notifier] = mutAppNotifier([$quiet, $loud]);

    $outcomes = $notifier->dispatch([Samples::alert('a')], 'My site', 'https://example.com');

    expect($notifier->channelNames())->toBe(['quiet', 'loud'])
        ->and($outcomes[0]->channel)->toBe('quiet')->and($outcomes[0]->status)->toBe(ChannelStatus::Nothing)->and($outcomes[0]->alerts)->toBe(0)
        ->and($outcomes[1]->channel)->toBe('loud')->and($outcomes[1]->status)->toBe(ChannelStatus::Sent)
        ->and($loud->sent[0]->siteName)->toBe('My site')->and($loud->sent[0]->siteUrl)->toBe('https://example.com');
});

it('sends a test message whatever the severity, and says whether it arrived', function (): void {
    $channel = new FakeChannel('chat', Severity::Critical);
    $channel->answers = [Delivery::delivered(), Delivery::failed('revoked')];
    [$notifier, $state] = mutAppNotifier([$channel]);

    $sent = $notifier->test('chat', 'My site', 'https://example.com');
    $failed = $notifier->test('chat', 'My site', 'https://example.com');
    $unknown = $notifier->test('nope', 'My site', 'https://example.com');

    expect([$sent->status, $sent->alerts, $sent->reason])->toBe([ChannelStatus::Sent, 1, ''])
        ->and([$failed->status, $failed->alerts, $failed->reason])->toBe([ChannelStatus::Failed, 1, 'revoked'])
        ->and([$unknown->status, $unknown->alerts, $unknown->reason])->toBe([ChannelStatus::Failed, 0, 'No channel is named "nope".'])
        ->and($channel->sent[0]->alerts[0]->title)->toBe('This is a test message from Hornet.')
        ->and($channel->sent[0]->alerts[0]->details)->toBe(['If you can read this, the channel works.'])
        ->and($state->told)->toBe([])->and($state->sent)->toBe([]);
});

it('reports a channel that blows up by the class of the failure only', function (): void {
    $broken = new class('broken') extends FakeChannel
    {
        public function send(Notification $notification): Delivery
        {
            throw new RuntimeException('https://hooks.example.com/very-private');
        }
    };
    [$notifier] = mutAppNotifier([$broken]);

    expect($notifier->test('broken', 'S', 'u')->reason)->toBe('The channel failed unexpectedly (RuntimeException).');
});

/**
 * A message that waits, due now.
 */
function mutAppQueued(InMemoryRetryQueue $queue, FakeClock $clock, string $id, string $channel = 'chat', int $attempts = 1): void
{
    $queue->push(new QueuedDelivery($id, $channel, Samples::notification([Samples::alert('a'), Samples::alert('b')]), $attempts, $clock->now()));
}

it('drops a message whose channel is gone, and goes on with the next', function (): void {
    $channel = new FakeChannel;
    [$notifier, , $queue, $clock] = mutAppNotifier([$channel]);
    mutAppQueued($queue, $clock, 'one', 'removed');
    mutAppQueued($queue, $clock, 'two');

    $outcomes = $notifier->retryDue();

    expect($outcomes)->toHaveCount(1)->and($outcomes[0]->status)->toBe(ChannelStatus::Sent)->and($outcomes[0]->alerts)->toBe(2)
        ->and($queue->count())->toBe(0);
});

it('leaves a message in the queue while its channel is at its limit, and goes on with the others', function (): void {
    $other = new FakeChannel('other');
    [$notifier, $state, $queue, $clock] = mutAppNotifier([new FakeChannel, $other], 3600, 1);
    $state->recordSent('chat', $clock->now());
    mutAppQueued($queue, $clock, 'one');
    mutAppQueued($queue, $clock, 'two', 'other');

    $outcomes = $notifier->retryDue();

    expect($outcomes)->toHaveCount(1)->and($outcomes[0]->channel)->toBe('other')
        ->and(array_keys($queue->items))->toBe(['one'])
        ->and($state->sentSince('other', $clock->now()))->toBe(1);
});

it('pushes a message back with one more attempt while it can still pass', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::retry('busy')];
    [$notifier, , $queue, $clock] = mutAppNotifier([$channel]);
    mutAppQueued($queue, $clock, 'one', attempts: 3);

    $outcome = $notifier->retryDue()[0];

    expect([$outcome->status, $outcome->alerts, $outcome->reason])->toBe([ChannelStatus::Queued, 2, 'busy'])
        ->and($queue->items['one']->attempts)->toBe(4)
        ->and($queue->items['one']->dueAt)->toEqual($clock->now()->add(new DateInterval('PT7200S')));
});

it('gives up at the fifth attempt, forgets what it told, and says it gave up', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::retry('busy')];
    [$notifier, $state, $queue, $clock] = mutAppNotifier([$channel]);
    $state->remember('chat', ['a', 'b'], $clock->now());
    mutAppQueued($queue, $clock, 'one', attempts: 4);

    $outcome = $notifier->retryDue()[0];

    expect($outcome->status)->toBe(ChannelStatus::Failed)->and($outcome->reason)->toBe('Gave up after 5 attempts: busy')
        ->and($queue->count())->toBe(0)
        ->and($state->lastTold('chat', 'a'))->toBeNull()->and($state->lastTold('chat', 'b'))->toBeNull();
});

it('gives the plain reason of a refusal on a retry', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::failed('revoked')];
    [$notifier, , $queue, $clock] = mutAppNotifier([$channel]);
    mutAppQueued($queue, $clock, 'one');

    expect($notifier->retryDue()[0]->reason)->toBe('revoked')->and($queue->count())->toBe(0);
});

// Alerts

it('makes an alert of each open finding, with a key that holds its severity', function (): void {
    $alerts = AlertFactory::fromReport(new Report([
        Finding::fail('xml-rpc', Severity::Medium, 'XML-RPC is on.', ['one', 'two'], 'Turn it off.'),
        Finding::pass('salts', 'Fine.'),
        Finding::fail('https', Severity::High, 'No HTTPS.')->ignoredFor('local'),
    ]));

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->toArray())->toBe(['key' => 'finding:xml-rpc:medium', 'severity' => 'medium', 'title' => 'XML-RPC is on.', 'details' => ['one', 'two'], 'fix' => 'Turn it off.']);
});

// Flood guard

it('writes up to the limit of a network, says once that it went past, then drops', function (): void {
    $guard = new FloodGuard(new InMemoryWindowCounter, 2, 600, 100);

    $decisions = array_map(static fn (): Decision => $guard->decide('203.0.113.0'), range(1, 5));

    expect($decisions)->toBe([Decision::Write, Decision::Write, Decision::FloodNetwork, Decision::Drop, Decision::Drop])
        ->and($guard->decide('198.51.100.0'))->toBe(Decision::Write);
});

it('bounds the whole site by the hour, saying once that it went past', function (): void {
    $guard = new FloodGuard(new InMemoryWindowCounter, 100, 600, 3);

    $decisions = array_map(static fn (int $n): Decision => $guard->decide('n'.$n), range(1, 6));

    expect($decisions)->toBe([Decision::Write, Decision::Write, Decision::Write, Decision::FloodSite, Decision::Drop, Decision::Drop]);
});

it('counts the site over one hour and a network over its window, each scope apart, and unknown networks together', function (): void {
    $counter = new class implements WindowCounter
    {
        public array $hits = [];

        public function hit(string $key, int $window): int
        {
            $this->hits[] = [$key, $window];

            return 1;
        }

        public function reset(): void {}
    };

    (new FloodGuard($counter))->decide('');
    (new FloodGuard($counter, scope: 'second-factor'))->decide('203.0.113.0');

    expect($counter->hits)->toBe([['journal:hour', 3600], ['journal:network:unknown', 600], ['second-factor:hour', 3600], ['second-factor:network:203.0.113.0', 600]]);
});

it('has limits of five per network in ten minutes and 120 an hour unless told otherwise', function (): void {
    $guard = new FloodGuard(new InMemoryWindowCounter);

    expect([$guard->perNetwork(), $guard->window(), $guard->perHour()])->toBe([5, 600, 120])
        ->and(array_map(static fn (): Decision => $guard->decide('a'), range(1, 6)))->toBe([...array_fill(0, 5, Decision::Write), Decision::FloodNetwork]);
});

// Journal recorder

it('writes the person logged in, cut address and cleaned facts', function (): void {
    $journal = new InMemoryJournal;
    $recorder = new JournalRecorder($journal, new FakeRequestContext(login: str_repeat('a', 80), address: '203.0.113.77'), new FakeClock);

    $written = $recorder->record(EventTypes::FINDING_RESTORED, str_repeat('s', 200), ['from' => 'x', 'password' => 'p']);
    $event = $journal->events[0];

    expect($written)->toBeTrue()
        ->and($event->userId)->toBe(7)
        ->and(mb_strlen($event->actor))->toBe(60)
        ->and(mb_strlen($event->subject))->toBe(120)
        ->and($event->ip)->toBe('203.0.113.0')
        ->and($event->details)->toBe(['from' => 'x'])
        ->and($recorder->failures())->toBe(0);
});

it('does not give another person the login of the one who is logged in', function (): void {
    $journal = new InMemoryJournal;
    $recorder = new JournalRecorder($journal, new FakeRequestContext, new FakeClock);

    $recorder->record(EventTypes::FINDING_RESTORED, userId: 9);
    $recorder->record(EventTypes::FINDING_RESTORED, userId: 7);
    $recorder->record(EventTypes::FINDING_RESTORED, userId: -3, actor: 'someone');

    expect([$journal->events[0]->userId, $journal->events[0]->actor])->toBe([9, ''])
        ->and([$journal->events[1]->userId, $journal->events[1]->actor])->toBe([7, 'olivier'])
        ->and([$journal->events[2]->userId, $journal->events[2]->actor])->toBe([0, 'someone']);
});

it('refuses a type it does not know, and counts what cannot be written without failing', function (): void {
    $journal = new InMemoryJournal;
    $recorder = new JournalRecorder($journal, new FakeRequestContext, new FakeClock);

    expect($recorder->record('made.up'))->toBeFalse()->and($journal->events)->toBe([])->and($recorder->failures())->toBe(0);

    $journal->failure = new RuntimeException('table gone');

    expect($recorder->record(EventTypes::FINDING_RESTORED))->toBeFalse()
        ->and($recorder->record(EventTypes::FINDING_RESTORED))->toBeFalse()
        ->and($recorder->failures())->toBe(2);
});

// Setting findings aside

/**
 * @param  list<array{string, string, array<string, mixed>}>  $written
 */
function mutAppAsides(InMemorySetAsideStore $store, array &$written, array $open = ['https'], array $inFile = ['salts']): SetAsideService
{
    return new SetAsideService($store, new FakeClock, static fn (): array => ['https', 'salts', 'xml-rpc'], static fn (): array => $open, static fn (): array => $inFile, static function (string $type, string $subject, array $details) use (&$written): void {
        $written[] = [$type, $subject, $details];
    });
}

it('sets an open finding aside with who, why and when, and says it in the journal', function (): void {
    $store = new InMemorySetAsideStore;
    $written = [];

    $aside = mutAppAsides($store, $written)->setAside('https', "  12345678 \u{200B} ", 3, str_repeat('l', 80));

    expect([$aside->check, $aside->reason, $aside->userId, mb_strlen($aside->login), $aside->at])->toBe(['https', '12345678', 3, 60, (new FakeClock)->now()->getTimestamp()])
        ->and($store->all()['https'])->toBe($aside)
        ->and($written)->toBe([[EventTypes::FINDING_SET_ASIDE, 'check:https', ['reason' => '12345678']]]);
});

it('cuts a reason that is too long', function (): void {
    $written = [];

    expect(mb_strlen(mutAppAsides(new InMemorySetAsideStore, $written)->setAside('https', str_repeat('é', 400), 1, 'a')->reason))->toBe(300);
});

it('refuses to hide a finding, and writes nothing', function (string $check, string $reason, string $why): void {
    $store = new InMemorySetAsideStore;
    $written = [];

    expect(fn () => mutAppAsides($store, $written)->setAside($check, $reason, 1, 'a'))->toThrow(SetAsideException::class, $why)
        ->and($store->all())->toBe([])->and($written)->toBe([]);
})->with(function (): array {
    return [
        'unknown check' => ['nope', 'a good reason', SetAsideException::unknownCheck('nope')->getMessage()],
        'locked by the file' => ['salts', 'a good reason', SetAsideException::lockedByFile('salts')->getMessage()],
        'seven characters' => ['https', '  1234567  ', SetAsideException::reasonNeeded(8)->getMessage()],
        'nothing open' => ['xml-rpc', 'a good reason', SetAsideException::notOpen('xml-rpc')->getMessage()],
    ];
});

it('lets the reason of a finding already set aside be changed, and restores it', function (): void {
    $store = new InMemorySetAsideStore(['xml-rpc' => new SetAside('xml-rpc', 'old reason', 1, 'a', 1)]);
    $written = [];
    $service = mutAppAsides($store, $written);

    $service->setAside('xml-rpc', 'a new reason', 2, 'b');

    expect($store->all()['xml-rpc']->reason)->toBe('a new reason');

    $service->restore('xml-rpc');

    expect($store->all())->toBe([])->and($written[1])->toBe([EventTypes::FINDING_RESTORED, 'check:xml-rpc', []]);
});

it('restores nothing that was not set aside, and refuses unknown or locked checks', function (): void {
    $store = new InMemorySetAsideStore(['salts' => new SetAside('salts', 'old reason', 1, 'a', 1)]);
    $written = [];
    $service = mutAppAsides($store, $written);

    $service->restore('https');

    expect($written)->toBe([])
        ->and(fn () => $service->restore('nope'))->toThrow(SetAsideException::class, SetAsideException::unknownCheck('nope')->getMessage())
        ->and(fn () => $service->restore('salts'))->toThrow(SetAsideException::class, SetAsideException::lockedByFile('salts')->getMessage())
        ->and($store->all())->toHaveKey('salts');
});

// Auditor

function mutAppCheck(string $id, ?Throwable $failure = null): Check
{
    return new class($id, $failure) implements Check
    {
        public function __construct(private readonly string $name, private readonly ?Throwable $failure) {}

        public function id(): string
        {
            return $this->name;
        }

        public function label(): string
        {
            return $this->name;
        }

        public function run(Environment $environment): Finding
        {
            return $this->failure instanceof Throwable ? throw $this->failure : Finding::fail($this->name, Severity::High, 'bad');
        }
    };
}

it('runs the checks that are not disabled, sets aside the ignored ones, and reports a crash', function (): void {
    $auditor = new Auditor([mutAppCheck('a'), mutAppCheck('b'), mutAppCheck('c', new RuntimeException('secret'))], ['a'], ['b' => 'because']);

    $report = $auditor->run(Mockery::mock(Environment::class));

    expect(array_keys($auditor->checks()))->toBe([0, 1])
        ->and(array_map(static fn (Finding $finding): string => $finding->checkId, $report->findings))->toBe(['b', 'c'])
        ->and($report->findings[0]->ignoredReason)->toBe('because')
        ->and($report->findings[1]->ignoredReason)->toBeNull()
        ->and($report->findings[1]->summary)->toBe('The check could not run: RuntimeException');
});

it('refuses a setting that names a check that does not exist', function (array $disabled, array $ignored): void {
    expect(fn () => new Auditor([mutAppCheck('a')], $disabled, $ignored))->toThrow(UnknownCheckException::class);
})->with(['disabled' => [['zz'], []], 'ignored' => [[], ['zz' => 'why']], 'after a good one' => [['a'], ['zz' => 'why']]]);

// Unlocker

it('forgets the lock of one address under its salted key, and refuses what is not an address', function (): void {
    $store = Mockery::mock(AttemptStore::class);
    $store->shouldReceive('forget')->once()->with((new ClientIp('203.0.113.7', '203.0.113.7'))->key('salt'));
    $store->shouldReceive('forgetAll')->once()->andReturn(4);
    $unlocker = new Unlocker($store, 'salt');

    expect($unlocker->unlock('203.0.113.7'))->toBeTrue()
        ->and($unlocker->unlock('not-an-address'))->toBeFalse()
        ->and($unlocker->unlockAll())->toBe(4);
});

it('tries the waiting messages again when asked to, and says what happened', function (): void {
    $clock = new FakeClock;
    $queue = new InMemoryRetryQueue;
    $channel = new FakeChannel;
    $queue->push(new QueuedDelivery('one', 'chat', Samples::notification(), 1, $clock->now()));
    $notifier = new Notifier([$channel], new InMemoryNotificationState, $queue, $clock, new Backoff, 3600, 20, static fn (): string => 'id');
    $service = new NotificationService(new Auditor([]), Mockery::mock(Environment::class), $notifier, new ArrayLock, $queue, 'S', 'u', []);

    $outcomes = $service->retry();

    expect($outcomes)->toHaveCount(1)->and($outcomes[0]->status)->toBe(ChannelStatus::Sent)->and($channel->sent)->toHaveCount(1);
});

it('adds the missing files to the count', function (): void {
    $list = array_map(md5(...), ['wp-includes/a.php' => 'a', 'wp-includes/gone.php' => 'g']);
    $task = new CoreIntegrityTask(new FakeCoreChecksums($list), new ArrayFileSystem(['wp-includes/a.php' => 'x']), '7.0', 'fr_FR');

    $finding = $task->run(null, false)->finding;

    expect($finding->summary)->toStartWith('2 core files differ')
        ->and($finding->details)->toBe(['Changed: wp-includes/a.php', 'Missing: wp-includes/gone.php']);
});

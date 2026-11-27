<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Illuminate\Support\ServiceProvider;
use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\Notification\NotificationService;
use Pollora\Hornet\Application\Notification\Notifier;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\HookServiceException;
use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Domain\Notification\Alert;
use Pollora\Hornet\Domain\Notification\Backoff;
use Pollora\Hornet\Domain\Notification\Delivery;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Domain\Notification\QueuedDelivery;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Infrastructure\Config\ChannelSpec;
use Pollora\Hornet\Infrastructure\Config\ChannelType;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Notification\ChannelFactory;
use Pollora\Hornet\Infrastructure\Notification\Channels\DiscordChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\SlackChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\TeamsChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\TelegramChannel;
use Pollora\Hornet\Infrastructure\Notification\NotificationFactory;
use Pollora\Hornet\Infrastructure\Pollora\CorePatches;
use Pollora\Hornet\Infrastructure\Pollora\HornetServiceProvider;
use Pollora\Hornet\Infrastructure\WpCli\HornetCommand;
use Pollora\Hornet\Infrastructure\WpCli\JournalCommand;
use Pollora\Hornet\Infrastructure\WpCli\NotifyCommand;
use Pollora\Hornet\Infrastructure\WpCli\Output;
use Pollora\Hornet\Infrastructure\WpCli\ScanCommand;
use Pollora\Hornet\Tests\Fixtures\ArrayLock;
use Pollora\Hornet\Tests\Fixtures\ArraySecrets;
use Pollora\Hornet\Tests\Fixtures\FakeApplication;
use Pollora\Hornet\Tests\Fixtures\FakeChannel;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;
use Pollora\Hornet\Tests\Fixtures\FakeMailer;
use Pollora\Hornet\Tests\Fixtures\InMemoryJournal;
use Pollora\Hornet\Tests\Fixtures\InMemoryNotificationState;
use Pollora\Hornet\Tests\Fixtures\InMemoryRetryQueue;
use Pollora\Hornet\Tests\Fixtures\InMemoryScanStore;
use Pollora\Hornet\Tests\Fixtures\Samples;

/**
 * An output that remembers which columns a table was given.
 */
function mutAppColumns(): Output
{
    return new class implements Output
    {
        /** @var list<string> */
        public array $fields = [];

        /** @var list<string> */
        public array $lines = [];

        /** @var list<array<string, string>> */
        public array $rows = [];

        public function line(string $message): void
        {
            $this->lines[] = $message;
        }

        public function success(string $message): void
        {
            $this->lines[] = 'success: '.$message;
        }

        public function warning(string $message): void
        {
            $this->lines[] = 'warning: '.$message;
        }

        public function error(string $message): void
        {
            $this->lines[] = 'error: '.$message;
        }

        public function items(array $rows, array $fields, string $format): void
        {
            $this->rows = $rows;
            $this->fields = $fields;
        }
    };
}

/**
 * @param  list<FakeChannel>  $channels
 */
function mutAppService(array $channels, ?InMemoryRetryQueue $queue = null): NotificationService
{
    $queue ??= new InMemoryRetryQueue;
    $notifier = new Notifier($channels, new InMemoryNotificationState, $queue, new FakeClock, new Backoff, 3600, 20, static fn (): string => 'id');

    return new NotificationService(new Auditor([]), Mockery::mock(Environment::class), $notifier, new ArrayLock, $queue, 'S', 'u', []);
}

it('names the columns of each table', function (): void {
    $status = mutAppColumns();
    (new HornetCommand(static fn (): Report => new Report([Finding::unknown('a', 'x')]), $status))->status();
    $scan = mutAppColumns();
    (new ScanCommand(static fn (): Scanner => new Scanner([], new InMemoryScanStore, new FakeClock), $scan))();
    $channels = mutAppColumns();
    (new NotifyCommand(static fn (): NotificationService => mutAppService([]), $channels))->status();
    $journal = new InMemoryJournal;
    $journal->events = [new Event(new DateTimeImmutable('2026-11-25 09:00:00 UTC'), 'login.failed', Source::Web, 1, '', '', '', [])];
    $list = mutAppColumns();
    (new JournalCommand(static fn (): InMemoryJournal => $journal, static fn () => null, static fn (): int => 1, new FakeClock, $list))->list();

    expect($status->fields)->toBe(['status', 'severity', 'check', 'summary'])
        ->and($scan->fields)->toBe(['status', 'severity', 'check', 'summary'])
        ->and($channels->fields)->toBe(['channel', 'type', 'min_severity'])
        ->and($list->fields)->toBe(['when', 'type', 'source', 'by', 'about', 'network', 'details'])
        ->and($list->rows[0]['by'])->toBe('#1');
});

it('lists fifty events of the journal unless told otherwise, and names the groups it takes', function (): void {
    $journal = new InMemoryJournal;
    $journal->events = array_fill(0, 60, new Event(new DateTimeImmutable('2026-11-25 09:00:00 UTC'), 'login.failed', Source::Web, 1, '', '', '', []));
    $output = mutAppColumns();
    $command = new JournalCommand(static fn (): InMemoryJournal => $journal, static fn () => null, static fn (): int => 1, new FakeClock, $output);

    $command->list();

    expect($output->rows)->toHaveCount(50);

    $command->list([], ['type' => 'nope']);

    expect($output->lines[0])->toEndWith('hornet.).');
});

it('says what each channel did, then whether any failed', function (): void {
    $clock = new FakeClock;
    $queue = new InMemoryRetryQueue;
    $ok = new FakeChannel('ok');
    $one = new FakeChannel('one');
    $one->answers = [Delivery::failed('revoked')];
    $queue->push(new QueuedDelivery('a', 'ok', Samples::notification([Samples::alert('a'), Samples::alert('b')]), 1, $clock->now()));
    $queue->push(new QueuedDelivery('b', 'one', Samples::notification(), 1, $clock->now()));
    $output = mutAppColumns();

    (new NotifyCommand(static fn (): NotificationService => mutAppService([$ok, $one], $queue), $output))->retry();

    expect($output->lines)->toBe([
        sprintf('%-20s %s', 'ok', 'sent (2 alerts)'),
        sprintf('%-20s %s', 'one', 'failed (1 alert): revoked'),
        'error: At least one channel failed.',
    ]);
});

it('says nothing of alerts for a channel that had none to send', function (): void {
    $output = mutAppColumns();

    (new NotifyCommand(static fn (): NotificationService => mutAppService([new FakeChannel('quiet')]), $output))->run();

    expect($output->lines)->toBe([sprintf('%-20s %s', 'quiet', 'nothing'), 'success: Done.']);
});

it('says once why there is no service', function (): void {
    $output = mutAppColumns();

    (new NotifyCommand(static fn (): never => throw InvalidSettingsException::unknownKey('x', 'notifications'), $output))->status();

    expect($output->lines)->toHaveCount(1)->and($output->fields)->toBe([]);
});

// The channels the settings describe

it('sends each channel where the settings say, with what they say', function (ChannelType $type, array $options, string $url): void {
    $http = new FakeHttpClient;
    $channel = (new ChannelFactory($http, new FakeMailer, new FakeClock))->channel(new ChannelSpec('c', $type, Severity::Low, $options));

    $channel->send(Samples::notification());

    expect($http->posts[0]['url'])->toBe($url);

    if ($type === ChannelType::Telegram) {
        expect($http->lastJson()['chat_id'])->toBe('-5');
    }

    if ($type === ChannelType::Webhook) {
        $post = $http->posts[0];

        expect($post['headers']['X-Hornet-Signature'])->toBe('sha256='.hash_hmac('sha256', $post['headers']['X-Hornet-Timestamp'].'.'.$post['body'], 'signing'));
    }
})->with([
    'slack' => [ChannelType::Slack, ['url' => Secret::of('https://a.example/slack')], 'https://a.example/slack'],
    'teams' => [ChannelType::Teams, ['url' => Secret::of('https://a.example/teams')], 'https://a.example/teams'],
    'discord' => [ChannelType::Discord, ['url' => Secret::of('https://a.example/discord')], 'https://a.example/discord'],
    'telegram' => [ChannelType::Telegram, ['token' => Secret::of('123'), 'chat_id' => '-5'], 'https://api.telegram.org/bot123/sendMessage'],
    'webhook' => [ChannelType::Webhook, ['url' => Secret::of('https://a.example/hook'), 'secret' => Secret::of('signing')], 'https://a.example/hook'],
]);

it('puts the service together with a clean, short name for the site, and what may be told of its channels', function (string $name, string $expected): void {
    Functions\when('translate')->returnArg(1);
    $http = new FakeHttpClient;
    $settings = Settings::fromArray(['notifications' => ['channels' => ['team' => ['type' => 'slack', 'url' => 'https://a.example/x', 'min_severity' => 'high']]]], new ArraySecrets);
    $factory = new NotificationFactory($http, new FakeMailer, new FakeClock, new InMemoryNotificationState, new InMemoryRetryQueue, new ArrayLock);

    $service = $factory->service($settings, Mockery::mock(Environment::class), $name, "https://example.com\x07", new Auditor([]));
    $service->test('team');

    expect($service->channels())->toHaveCount(1)
        ->and([$service->channels()[0]->name, $service->channels()[0]->type, $service->channels()[0]->minSeverity])->toBe(['team', 'slack', Severity::High])
        ->and($http->lastJson()['text'])->toBe('[Hornet] '.$expected.': 1 security finding (info)')
        ->and($http->lastJson()['blocks'][2]['elements'][0]['text'])->toBe('https://example.com');
})->with([
    'long' => [str_repeat('n', 150), str_repeat('n', 99).'…'],
    'a hundred' => [str_repeat('n', 100), str_repeat('n', 100)],
    'nothing' => ["\x07", 'WordPress'],
]);

// The exact shape each service asks for

it('sends Teams the card its workflow expects', function (): void {
    $http = new FakeHttpClient;
    (new TeamsChannel('t', Severity::Low, $http, Secret::of('https://a.example/x')))->send(new Notification('i', 's', 'u', [new Alert('k', Severity::Low, 't')], new DateTimeImmutable));
    $card = $http->lastJson()['attachments'][0];
    [$title, $lines, $site] = $card['content']['body'];

    expect($http->posts[0]['headers'])->toBe(['Content-Type' => 'application/json'])
        ->and(array_keys($card))->toBe(['contentType', 'contentUrl', 'content'])
        ->and($card['content']['$schema'])->toBe('http://adaptivecards.io/schemas/adaptive-card.json')
        ->and([$title['type'], $title['weight'], $title['size'], $title['wrap']])->toBe(['TextBlock', 'Bolder', 'Medium', true])
        ->and([$lines['type'], $lines['wrap']])->toBe(['TextBlock', true])
        ->and([$site['type'], $site['isSubtle'], $site['wrap']])->toBe(['TextBlock', true, true]);
});

it('says to each service that the body is JSON, and to Slack that its text is Markdown', function (string $class): void {
    $http = new FakeHttpClient;
    $channel = $class === TelegramChannel::class ? new TelegramChannel('c', Severity::Low, $http, Secret::of('t'), '1') : new $class('c', Severity::Low, $http, Secret::of('https://a.example/x'));

    $channel->send(Samples::notification());

    expect($http->posts[0]['headers'])->toBe(['Content-Type' => 'application/json']);

    if ($class === SlackChannel::class) {
        expect($http->lastJson()['blocks'][1]['text']['type'])->toBe('mrkdwn')->and($http->lastJson()['blocks'][2]['elements'][0]['type'])->toBe('mrkdwn');
    }
})->with([SlackChannel::class, DiscordChannel::class, TelegramChannel::class]);

it('tries a Telegram message again after a server failure, whatever its body says', function (): void {
    $http = new FakeHttpClient;
    $http->answers = [new HttpResponse(500, null, '{"ok":false}')];

    expect((new TelegramChannel('g', Severity::Low, $http, Secret::of('t'), '1'))->send(Samples::notification())->retryable)->toBeTrue();
});

// Settings written wrong

it('refuses a setting written without a name as a setting, in every section', function (array $config): void {
    expect(fn () => Settings::fromArray($config, new ArraySecrets))->toThrow(InvalidSettingsException::class);
})->with([
    'at the top' => [['x']],
    'notifications' => [['notifications' => ['x']]],
    'a channel' => [['notifications' => ['channels' => ['x']]]],
    'headers' => [['headers' => ['x']]],
    'hsts' => [['headers' => ['hsts' => ['max_age' => 600, 0 => 'x']]]],
    'csp' => [['headers' => ['csp' => ['policy' => 'a', 0 => 'x']]]],
    'hardening' => [['hardening' => ['x']]],
    'login' => [['login' => ['x']]],
    'journal' => [['journal' => ['x']]],
    'vulnerabilities' => [['vulnerabilities' => ['x']]],
    'abilities' => [['abilities' => ['x']]],
    'passwords' => [['passwords' => ['x']]],
    'network' => [['network' => ['x']]],
    'scanner' => [['scanner' => ['x']]],
]);

it('says in full which setting is wrong and what it takes', function (array $config, string $message): void {
    expect(fn () => Settings::fromArray($config, new ArraySecrets))->toThrow(InvalidSettingsException::class, $message);
})->with([
    [['notifications' => ['cooldown' => 1]], '"notifications.cooldown"'],
    [['notifications' => ['channels' => ['c' => ['type' => 'x']]]], 'telegram, ntfy.'],
    [['network' => ['forwarded_header' => 'x']], 'one of X-Forwarded-For'],
    [['notifications' => ['channels' => ['c' => ['type' => 'webhook', 'secret' => 's']]]], '"url" is required'],
    [['notifications' => ['channels' => ['c' => ['type' => 'slack', 'url' => 'https:private']]]], 'https address.'],
]);

// Pollora

it('publishes the settings file of the package where Pollora reads it', function (): void {
    Functions\when('did_action')->justReturn(0);
    $app = new FakeApplication;

    (new HornetServiceProvider($app))->boot();
    $paths = ServiceProvider::pathsToPublish(HornetServiceProvider::class, 'hornet-config');

    expect($paths)->toContain('/nowhere/config/pollora/hornet.php')
        ->and(array_map(realpath(...), array_keys($paths)))->toContain(realpath(__DIR__.'/../../../config/hornet.php'));
});

it('says that a hook service that is not an object cannot be used', function (): void {
    $app = new FakeApplication;
    $app->bindings['Pollora\\Hook\\Domain\\Contract\\Action'] = new stdClass;
    $app->bindings['Pollora\\Hook\\Domain\\Contract\\Filter'] = 'text';

    expect(fn () => (new HornetServiceProvider($app))->start())->toThrow(HookServiceException::class);
});

it('reads on after a file a patch deletes', function (): void {
    expect(CorePatches::touched("+++ /dev/null\n+++ b/wp-load.php"))->toBe(['wp-load.php']);
});

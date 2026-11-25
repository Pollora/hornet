<?php

declare(strict_types=1);

use Pollora\Hornet\Application\AlertFactory;
use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Application\Notification\ChannelInfo;
use Pollora\Hornet\Application\Notification\ChannelStatus;
use Pollora\Hornet\Application\Notification\NotificationService;
use Pollora\Hornet\Application\Notification\Notifier;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Report;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\Backoff;
use Pollora\Hornet\Tests\Fixtures\ArrayLock;
use Pollora\Hornet\Tests\Fixtures\FakeChannel;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\InMemoryNotificationState;
use Pollora\Hornet\Tests\Fixtures\InMemoryRetryQueue;

function serviceFor(FakeChannel $channel, ?ArrayLock $lock = null, ?InMemoryRetryQueue $queue = null): NotificationService
{
    $queue ??= new InMemoryRetryQueue;

    return new NotificationService(
        new Auditor(DefaultChecks::all()),
        new FakeEnvironment(xmlRpc: true),
        new Notifier([$channel], new InMemoryNotificationState, $queue, new FakeClock, new Backoff, 86400, 20, static fn (): string => 'abcdef0123456789'),
        $lock ?? new ArrayLock,
        $queue,
        'My site',
        'https://example.com',
        [new ChannelInfo('chat', 'slack', Severity::Medium)],
    );
}

describe('alert factory', function (): void {
    it('makes one alert per open finding, with the severity in the key', function (): void {
        $alerts = AlertFactory::fromReport(new Report([
            Finding::pass('a', 'fine'),
            Finding::fail('xml-rpc', Severity::Medium, 'XML-RPC is enabled.', ['d'], 'Turn it off.'),
            Finding::fail('https', Severity::High, 'No HTTPS.')->ignoredFor('Behind a proxy.'),
            Finding::unknown('c', 'cannot tell'),
        ]));

        expect($alerts)->toHaveCount(1)->and($alerts[0]->key)->toBe('finding:xml-rpc:medium')->and($alerts[0]->title)->toBe('XML-RPC is enabled.')
            ->and($alerts[0]->details)->toBe(['d'])->and($alerts[0]->fix)->toBe('Turn it off.');
    });

    it('makes a worse finding a new alert', function (): void {
        $medium = AlertFactory::fromReport(new Report([Finding::fail('x', Severity::Medium, 's')]));
        $high = AlertFactory::fromReport(new Report([Finding::fail('x', Severity::High, 's')]));

        expect($medium[0]->key)->not->toBe($high[0]->key);
    });
});

describe('service', function (): void {
    it('audits, tells the channels, and lets go of the lock', function (): void {
        $channel = new FakeChannel;
        $lock = new ArrayLock;
        $run = serviceFor($channel, $lock)->run();

        expect($run->report)->toBeInstanceOf(Report::class)
            ->and($run->outcomes[0]->status)->toBe(ChannelStatus::Sent)
            ->and($channel->sent[0]->siteName)->toBe('My site')
            ->and($lock->held)->toBeFalse()->and($lock->released)->toBe(1);
    });

    it('steps aside when another run holds the lock, and touches nothing', function (): void {
        $channel = new FakeChannel;
        $lock = new ArrayLock;
        $lock->held = true;
        $run = serviceFor($channel, $lock)->run();

        expect($run->report)->toBeNull()->and($run->outcomes)->toBe([])->and($channel->sent)->toBe([])->and($lock->released)->toBe(0);
    });

    it('lets go of the lock when the run fails', function (): void {
        $lock = new ArrayLock;
        $queue = new class extends InMemoryRetryQueue
        {
            public function due(DateTimeImmutable $now): array
            {
                throw new RuntimeException('boom');
            }
        };
        $service = new NotificationService(
            new Auditor(DefaultChecks::all()),
            new FakeEnvironment,
            new Notifier([new FakeChannel], new InMemoryNotificationState, $queue, new FakeClock, new Backoff, 86400, 20, static fn (): string => 'abcdef0123456789'),
            $lock,
            $queue,
            'S',
            'u',
            [],
        );

        expect(fn () => $service->run())->toThrow(RuntimeException::class, 'boom')->and($lock->held)->toBeFalse();
    });

    it('lists the channels, the queue and sends a test', function (): void {
        $channel = new FakeChannel;
        $service = serviceFor($channel);

        expect($service->channels()[0]->name)->toBe('chat')->and($service->queued())->toBe(0)
            ->and($service->test('chat')->status)->toBe(ChannelStatus::Sent)->and($service->retry())->toBe([]);
    });
});

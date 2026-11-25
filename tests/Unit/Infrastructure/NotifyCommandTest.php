<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Application\Notification\ChannelInfo;
use Pollora\Hornet\Application\Notification\NotificationService;
use Pollora\Hornet\Application\Notification\Notifier;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Notification\Backoff;
use Pollora\Hornet\Domain\Notification\Delivery;
use Pollora\Hornet\Infrastructure\WpCli\NotifyCommand;
use Pollora\Hornet\Tests\Fixtures\ArrayLock;
use Pollora\Hornet\Tests\Fixtures\FakeChannel;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\InMemoryNotificationState;
use Pollora\Hornet\Tests\Fixtures\InMemoryRetryQueue;
use Pollora\Hornet\Tests\Fixtures\RecordingOutput;

function notifyCommand(FakeChannel $channel, RecordingOutput $output, ?ArrayLock $lock = null, ?InMemoryRetryQueue $queue = null): NotifyCommand
{
    $queue ??= new InMemoryRetryQueue;
    $service = new NotificationService(
        new Auditor(DefaultChecks::all()),
        new FakeEnvironment(xmlRpc: true),
        new Notifier([$channel], new InMemoryNotificationState, $queue, new FakeClock, new Backoff, 86400, 20, static fn (): string => 'abcdef0123456789'),
        $lock ?? new ArrayLock,
        $queue,
        'S',
        'u',
        [new ChannelInfo('chat', 'slack', Severity::Medium)],
    );

    return new NotifyCommand(fn () => $service, $output);
}

it('runs an audit and says what each channel got', function (): void {
    $output = new RecordingOutput;
    notifyCommand(new FakeChannel, $output)->run();

    expect($output->lines[0])->toContain('chat')->toContain('sent')->toContain('alert')->and($output->successes)->toBe(['Done.']);
});

it('says so when another run is in progress', function (): void {
    $lock = new ArrayLock;
    $lock->held = true;
    $output = new RecordingOutput;
    notifyCommand(new FakeChannel, $output, $lock)->run();

    expect($output->warnings[0])->toContain('Another run is in progress');
});

it('fails when a channel could not be told', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::failed('The service refused the message (HTTP 404).')];

    expect(fn () => notifyCommand($channel, new RecordingOutput)->run())->toThrow(RuntimeException::class, 'At least one channel failed.');
});

it('sends a test message', function (): void {
    $output = new RecordingOutput;
    $channel = new FakeChannel;
    notifyCommand($channel, $output)->test(['chat']);

    expect($output->successes[0])->toBe('The test message reached "chat".')->and($channel->sent)->toHaveCount(1);
});

it('fails a test that did not go through, and asks for a channel name', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::failed('The service refused the message (HTTP 403).')];

    expect(fn () => notifyCommand($channel, new RecordingOutput)->test(['chat']))->toThrow(RuntimeException::class, 'HTTP 403')
        ->and(fn () => notifyCommand(new FakeChannel, new RecordingOutput)->test([]))->toThrow(RuntimeException::class, 'Usage');
});

it('lists the channels without any address, and the queue', function (): void {
    $output = new RecordingOutput;
    notifyCommand(new FakeChannel, $output)->status();

    expect($output->rows)->toBe([['channel' => 'chat', 'type' => 'slack', 'min_severity' => 'medium']])->and($output->lines[0])->toBe('0 message(s) waiting for another attempt.');
});

it('tries the waiting messages again', function (): void {
    $output = new RecordingOutput;
    notifyCommand(new FakeChannel, $output)->retry();

    expect($output->successes)->toBe(['Nothing was due.']);
});

it('says why when there is nothing to run: no channel, or wrong settings', function (): void {
    expect(fn () => (new NotifyCommand(fn () => null, new RecordingOutput))->status())->toThrow(RuntimeException::class, 'No notification channel is configured')
        ->and(fn () => (new NotifyCommand(fn () => throw InvalidSettingsException::channel('x', 'bad.'), new RecordingOutput))->run())->toThrow(RuntimeException::class, 'channel "x"');
});

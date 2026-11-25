<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Application\Auditor;
use Pollora\Hornet\Application\DefaultChecks;
use Pollora\Hornet\Application\Notification\NotificationService;
use Pollora\Hornet\Application\Notification\Notifier;
use Pollora\Hornet\Domain\Notification\Backoff;
use Pollora\Hornet\Domain\Notification\Delivery;
use Pollora\Hornet\Infrastructure\WordPress\NotificationModule;
use Pollora\Hornet\Tests\Fixtures\ArrayLock;
use Pollora\Hornet\Tests\Fixtures\FakeChannel;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\InMemoryNotificationState;
use Pollora\Hornet\Tests\Fixtures\InMemoryRetryQueue;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

function moduleService(?FakeChannel $channel = null): NotificationService
{
    $queue = new InMemoryRetryQueue;

    return new NotificationService(
        new Auditor(DefaultChecks::all()),
        new FakeEnvironment(xmlRpc: true),
        new Notifier([$channel ?? new FakeChannel], new InMemoryNotificationState, $queue, new FakeClock, new Backoff, 86400, 20, static fn (): string => 'abcdef0123456789'),
        new ArrayLock,
        $queue,
        'S',
        'u',
        [],
    );
}

it('attaches the init hook, the audit and the retry', function (): void {
    $hooks = new RecordingHooks;
    (new NotificationModule(fn () => moduleService(), 'daily'))->register($hooks);

    expect(array_column($hooks->registered, 'hook'))->toBe(['init', 'pollora/hornet/scheduled_audit', 'pollora/hornet/retry_notifications']);
});

it('schedules the audit once, five minutes from now', function (): void {
    Functions\when('wp_get_schedule')->justReturn(false);
    Functions\expect('wp_clear_scheduled_hook')->once()->with('pollora/hornet/scheduled_audit');
    Functions\expect('wp_schedule_event')->once()->withArgs(fn (int $when, string $recurrence, string $hook): bool => $when > time() + 200 && $when <= time() + 301 && $recurrence === 'daily' && $hook === 'pollora/hornet/scheduled_audit');

    (new NotificationModule(fn () => moduleService(), 'daily'))->plan();
});

it('leaves a schedule alone when it is the one asked for', function (): void {
    Functions\when('wp_get_schedule')->justReturn('daily');
    Functions\expect('wp_schedule_event')->never();
    Functions\expect('wp_clear_scheduled_hook')->never();

    (new NotificationModule(fn () => moduleService(), 'daily'))->plan();
});

it('moves the audit when the schedule changes', function (): void {
    Functions\when('wp_get_schedule')->justReturn('daily');
    Functions\expect('wp_clear_scheduled_hook')->once();
    Functions\expect('wp_schedule_event')->once()->withArgs(fn (int $when, string $recurrence): bool => $recurrence === 'hourly');

    (new NotificationModule(fn () => moduleService(), 'hourly'))->plan();
});

it('takes the audit out of the cron when the schedule is off', function (): void {
    Functions\when('wp_get_schedule')->justReturn('daily');
    Functions\expect('wp_clear_scheduled_hook')->once()->with('pollora/hornet/scheduled_audit');
    Functions\expect('wp_schedule_event')->never();

    (new NotificationModule(fn () => moduleService(), 'off'))->plan();
});

it('does nothing when the schedule is off and nothing was scheduled', function (): void {
    Functions\when('wp_get_schedule')->justReturn(false);
    Functions\expect('wp_clear_scheduled_hook')->never();

    (new NotificationModule(fn () => moduleService(), 'off'))->plan();
});

it('runs the audit, and plans a retry when a message waits', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::retry('down')];
    $built = 0;
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\expect('wp_schedule_single_event')->once()->withArgs(fn (int $when, string $hook): bool => $hook === 'pollora/hornet/retry_notifications' && $when > time() + 200);

    (new NotificationModule(function () use ($channel, &$built): NotificationService {
        $built++;

        return moduleService($channel);
    }, 'daily'))->audit();

    expect($channel->sent)->toHaveCount(1)->and($built)->toBe(1);
});

it('does not plan a second retry while one is planned, nor when nothing waits', function (): void {
    Functions\when('wp_next_scheduled')->justReturn(1234567890);
    Functions\expect('wp_schedule_single_event')->never();
    $channel = new FakeChannel;
    $channel->answers = [Delivery::retry('down')];

    (new NotificationModule(fn () => moduleService($channel), 'daily'))->audit();
    (new NotificationModule(fn () => moduleService(), 'daily'))->retry();
});

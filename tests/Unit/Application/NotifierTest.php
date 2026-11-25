<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Notification\ChannelStatus;
use Pollora\Hornet\Application\Notification\Notifier;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\Alert;
use Pollora\Hornet\Domain\Notification\Backoff;
use Pollora\Hornet\Domain\Notification\Delivery;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Tests\Fixtures\FakeChannel;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\InMemoryNotificationState;
use Pollora\Hornet\Tests\Fixtures\InMemoryRetryQueue;
use Pollora\Hornet\Tests\Fixtures\Samples;

/**
 * @param  list<FakeChannel>  $channels
 * @return array{Notifier, InMemoryNotificationState, InMemoryRetryQueue, FakeClock}
 */
function notifier(array $channels, int $cooldown = 86400, int $rateLimit = 20): array
{
    $state = new InMemoryNotificationState;
    $queue = new InMemoryRetryQueue;
    $clock = new FakeClock;
    $counter = 0;

    return [new Notifier($channels, $state, $queue, $clock, new Backoff, $cooldown, $rateLimit, static function () use (&$counter): string {
        return sprintf('%016x', ++$counter);
    }), $state, $queue, $clock];
}

it('sends one message with every alert a channel is due, and remembers them', function (): void {
    $channel = new FakeChannel;
    [$notifier, $state] = notifier([$channel]);

    $outcomes = $notifier->dispatch([Samples::alert('a'), Samples::alert('b', Severity::High)], 'My site', 'https://example.com');

    expect($outcomes[0]->status)->toBe(ChannelStatus::Sent)->and($outcomes[0]->alerts)->toBe(2)
        ->and($channel->sent)->toHaveCount(1)->and($channel->sent[0]->alerts)->toHaveCount(2)
        ->and($state->told['chat'])->toHaveKeys(['a', 'b']);
});

it('does not repeat an alert within the cooldown, and tells it again after', function (): void {
    $channel = new FakeChannel;
    [$notifier, , , $clock] = notifier([$channel], 3600);

    $notifier->dispatch([Samples::alert('a')], 'S', 'u');
    $clock->advance('PT30M');
    $again = $notifier->dispatch([Samples::alert('a')], 'S', 'u');

    expect($again[0]->status)->toBe(ChannelStatus::Nothing)->and($channel->sent)->toHaveCount(1);

    $clock->advance('PT31M');
    $later = $notifier->dispatch([Samples::alert('a')], 'S', 'u');

    expect($later[0]->status)->toBe(ChannelStatus::Sent)->and($channel->sent)->toHaveCount(2);
});

it('tells only what is new', function (): void {
    $channel = new FakeChannel;
    [$notifier] = notifier([$channel]);

    $notifier->dispatch([Samples::alert('a')], 'S', 'u');
    $notifier->dispatch([Samples::alert('a'), Samples::alert('b')], 'S', 'u');

    expect(array_map(static fn (Alert $alert): string => $alert->key, $channel->sent[1]->alerts))->toBe(['b']);
});

it('keeps below the severity a channel asked for', function (): void {
    $quiet = new FakeChannel('quiet', Severity::High);
    $loud = new FakeChannel('loud', Severity::Low);
    [$notifier] = notifier([$quiet, $loud]);

    $outcomes = $notifier->dispatch([Samples::alert('a', Severity::Medium)], 'S', 'u');

    expect($outcomes[0]->status)->toBe(ChannelStatus::Nothing)->and($outcomes[1]->status)->toBe(ChannelStatus::Sent)->and($quiet->sent)->toBe([]);
});

it('keeps each channel apart: one told does not silence another', function (): void {
    $one = new FakeChannel('one');
    $two = new FakeChannel('two');
    [$notifier] = notifier([$one]);
    $notifier->dispatch([Samples::alert('a')], 'S', 'u');
    [$both] = notifier([$one, $two]);

    expect($both->dispatch([Samples::alert('a')], 'S', 'u')[1]->status)->toBe(ChannelStatus::Sent);
});

it('gives a channel its share an hour and keeps the rest for the next run', function (): void {
    $channel = new FakeChannel;
    [$notifier, $state, , $clock] = notifier([$channel], 60, 2);

    expect($notifier->dispatch([Samples::alert('a')], 'S', 'u')[0]->status)->toBe(ChannelStatus::Sent);
    $clock->advance('PT2M');
    expect($notifier->dispatch([Samples::alert('b')], 'S', 'u')[0]->status)->toBe(ChannelStatus::Sent);
    $clock->advance('PT2M');
    $limited = $notifier->dispatch([Samples::alert('c')], 'S', 'u');

    expect($limited[0]->status)->toBe(ChannelStatus::RateLimited)->and($channel->sent)->toHaveCount(2)
        // Not remembered: the next run tells it
        ->and($state->told['chat'])->not->toHaveKey('c');

    $clock->advance('PT1H');

    expect($notifier->dispatch([Samples::alert('c')], 'S', 'u')[0]->status)->toBe(ChannelStatus::Sent);
});

it('queues a message that failed for a reason that can pass, and does not send the same news twice meanwhile', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::retry('The service failed (HTTP 503).', 120)];
    [$notifier, $state, $queue, $clock] = notifier([$channel]);

    $first = $notifier->dispatch([Samples::alert('a')], 'S', 'u');
    $second = $notifier->dispatch([Samples::alert('a')], 'S', 'u');

    expect($first[0]->status)->toBe(ChannelStatus::Queued)->and($first[0]->reason)->toContain('503')
        ->and($queue->count())->toBe(1)
        ->and($second[0]->status)->toBe(ChannelStatus::Nothing)
        ->and(array_values($queue->items)[0]->dueAt)->toEqual($clock->now()->add(new DateInterval('PT120S')))
        ->and($state->told['chat'])->toHaveKey('a');
});

it('does not queue a failure that cannot pass, and tells it again at the next run', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::failed('The service refused the message (HTTP 404).')];
    [$notifier, $state, $queue] = notifier([$channel]);

    $outcome = $notifier->dispatch([Samples::alert('a')], 'S', 'u');

    expect($outcome[0]->status)->toBe(ChannelStatus::Failed)->and($queue->count())->toBe(0)->and($state->told)->toBe([]);
});

it('sends a waiting message when its time comes, and not before', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::retry('down')];
    [$notifier, , $queue, $clock] = notifier([$channel]);
    $notifier->dispatch([Samples::alert('a')], 'S', 'u');

    expect($notifier->retryDue())->toBe([]);

    $clock->advance('PT61S');
    $retried = $notifier->retryDue();

    expect($retried[0]->status)->toBe(ChannelStatus::Sent)->and($queue->count())->toBe(0)->and($channel->sent)->toHaveCount(2);
});

it('waits longer each time, then gives up and forgets what it told so that the next audit announces it again', function (): void {
    $channel = new FakeChannel;
    $channel->answers = array_fill(0, 10, Delivery::retry('down'));
    [$notifier, $state, $queue, $clock] = notifier([$channel]);
    $notifier->dispatch([Samples::alert('a')], 'S', 'u');
    $waited = [];
    $last = null;

    for ($i = 0; $i < 6; $i++) {
        $clock->advance('PT3H');
        $outcomes = $notifier->retryDue();
        $last = $outcomes[0] ?? $last;

        foreach ($queue->items as $item) {
            $waited[] = $item->attempts;
        }
    }

    expect($waited)->toBe([2, 3, 4])->and($last?->status)->toBe(ChannelStatus::Failed)->and($last?->reason)->toContain('Gave up after 5 attempts')
        ->and($queue->count())->toBe(0)->and($state->told['chat'] ?? [])->not->toHaveKey('a');
});

it('drops a waiting message whose channel was removed from the settings', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::retry('down')];
    [$notifier, , $queue, $clock] = notifier([$channel]);
    $notifier->dispatch([Samples::alert('a')], 'S', 'u');

    $other = new Notifier([new FakeChannel('other')], new InMemoryNotificationState, $queue, $clock, new Backoff, 60, 20, static fn (): string => 'x');
    $clock->advance('PT1H');

    expect($other->retryDue())->toBe([])->and($queue->count())->toBe(0);
});

it('leaves a waiting message alone while its channel has used its hour', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::retry('down')];
    [$notifier, $state, $queue, $clock] = notifier([$channel], 86400, 1);
    $notifier->dispatch([Samples::alert('a')], 'S', 'u');
    $clock->advance('PT61S');
    $state->recordSent('chat', $clock->now());

    expect($notifier->retryDue())->toBe([])->and($queue->count())->toBe(1);
});

it('sends a test message to one channel whatever its severity and cooldown, and remembers nothing', function (): void {
    $channel = new FakeChannel('quiet', Severity::Critical);
    [$notifier, $state] = notifier([$channel]);

    $outcome = $notifier->test('quiet', 'My site', 'https://example.com');

    expect($outcome->status)->toBe(ChannelStatus::Sent)->and($channel->sent[0]->alerts[0]->title)->toContain('test message')
        ->and($state->told)->toBe([])->and($state->sent)->toBe([]);
});

it('reports a test that failed, and a channel that does not exist', function (): void {
    $channel = new FakeChannel;
    $channel->answers = [Delivery::failed('The service refused the message (HTTP 403).')];
    [$notifier] = notifier([$channel]);

    expect($notifier->test('chat', 'S', 'u')->status)->toBe(ChannelStatus::Failed)
        ->and($notifier->test('nope', 'S', 'u')->reason)->toContain('No channel is named "nope"')
        ->and($notifier->channelNames())->toBe(['chat']);
});

it('keeps telling the other channels when one breaks, and names only the class of the error', function (): void {
    $broken = new class('broken') extends FakeChannel
    {
        public function send(Notification $notification): Delivery
        {
            throw new RuntimeException('secret https://hooks.example.com/TOKEN');
        }
    };
    $fine = new FakeChannel('fine');
    [$notifier] = notifier([$broken, $fine]);

    $outcomes = $notifier->dispatch([Samples::alert('a')], 'S', 'u');

    expect($outcomes[0]->status)->toBe(ChannelStatus::Failed)->and($outcomes[0]->reason)->toBe('The channel failed unexpectedly (RuntimeException).')
        ->and($outcomes[0]->reason)->not->toContain('TOKEN')
        ->and($outcomes[1]->status)->toBe(ChannelStatus::Sent);
});

<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\Alert;
use Pollora\Hornet\Domain\Notification\Backoff;
use Pollora\Hornet\Domain\Notification\Delivery;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Tests\Fixtures\Samples;

describe('secret', function (): void {
    it('prints as redacted everywhere', function (): void {
        $secret = Secret::of('https://hooks.example.com/TOKEN123');

        expect((string) $secret)->toBe('[redacted]')
            ->and(json_encode(['url' => $secret]))->toBe('{"url":"[redacted]"}')
            ->and(var_export((string) $secret, true))->not->toContain('TOKEN123')
            ->and($secret->__debugInfo())->toBe(['value' => '[redacted]'])
            ->and($secret->reveal())->toBe('https://hooks.example.com/TOKEN123');
    });

    it('refuses to be serialized, so that it cannot reach a cache or a log', function (): void {
        expect(fn (): string => serialize(Secret::of('x')))->toThrow(LogicException::class);
    });
});

describe('delivery', function (): void {
    it('reads an HTTP status', function (int $status, bool $delivered, bool $retryable): void {
        $delivery = Delivery::fromStatus($status, 30);

        expect($delivery->delivered)->toBe($delivered)->and($delivery->retryable)->toBe($retryable);
    })->with([
        '200' => [200, true, false],
        '204' => [204, true, false],
        '429 can pass' => [429, false, true],
        '408 can pass' => [408, false, true],
        '500 can pass' => [500, false, true],
        '503 can pass' => [503, false, true],
        '400 cannot' => [400, false, false],
        '401 cannot' => [401, false, false],
        '403 cannot' => [403, false, false],
        '404 cannot' => [404, false, false],
        '302 cannot' => [302, false, false],
    ]);

    it('keeps the wait a service asked for', function (): void {
        expect(Delivery::fromStatus(429, 42)->retryAfter)->toBe(42)->and(Delivery::fromStatus(500)->retryAfter)->toBeNull();
    });

    it('never puts an address in a reason', function (): void {
        expect(Delivery::fromStatus(404)->reason)->not->toContain('http');
    });
});

describe('backoff', function (): void {
    it('waits 1 minute, 5 minutes, 30 minutes, 2 hours, then gives up', function (): void {
        $backoff = new Backoff;

        expect([$backoff->delay(1), $backoff->delay(2), $backoff->delay(3), $backoff->delay(4)])->toBe([60, 300, 1800, 7200])
            ->and($backoff->delay(9))->toBe(7200)
            ->and($backoff->maxAttempts())->toBe(5)
            ->and($backoff->exhausted(4))->toBeFalse()
            ->and($backoff->exhausted(5))->toBeTrue();
    });

    it('obeys a service that asks for more, within an hour', function (): void {
        $backoff = new Backoff;

        expect($backoff->delay(1, 600))->toBe(600)
            ->and($backoff->delay(1, 999999))->toBe(3600)
            ->and($backoff->delay(3, 10))->toBe(1800)
            ->and($backoff->delay(1, -5))->toBe(60);
    });
});

describe('notification', function (): void {
    it('is as loud as its worst alert', function (): void {
        $notification = Samples::notification([Samples::alert('a', Severity::Low), Samples::alert('b', Severity::High), Samples::alert('c', Severity::Medium)]);

        expect($notification->severity())->toBe(Severity::High)
            ->and($notification->subject())->toBe('[Hornet] My site: 3 security findings (high)');
    });

    it('says finding in the singular for one', function (): void {
        expect(Samples::notification()->subject())->toBe('[Hornet] My site: 1 security finding (medium)');
    });

    it('survives being stored and read back', function (): void {
        $notification = Samples::notification([Samples::alert('a', Severity::High, 'T')]);
        $back = Notification::fromArray(json_decode((string) json_encode($notification->toArray()), true));

        expect($back)->not->toBeNull()->and($back?->toArray())->toBe($notification->toArray());
    });

    it('refuses damaged stored data', function (mixed $data): void {
        expect(Notification::fromArray($data))->toBeNull();
    })->with([
        'no alerts' => [['id' => 'x', 'created_at' => '2026-11-25T10:00:00+00:00', 'alerts' => []]],
        'no id' => [['created_at' => '2026-11-25T10:00:00+00:00', 'alerts' => [['key' => 'k', 'severity' => 'low', 'title' => 't']]]],
        'no date' => [['id' => 'x', 'alerts' => [['key' => 'k', 'severity' => 'low', 'title' => 't']]]],
    ]);

    it('reads an alert with missing or wrong fields without failing', function (): void {
        $alert = Alert::fromArray(['key' => 5, 'severity' => 'bogus', 'details' => ['a', 3, 'b']]);

        expect($alert->key)->toBe('')->and($alert->severity)->toBe(Severity::Info)->and($alert->details)->toBe(['a', 'b'])->and($alert->fix)->toBeNull();
    });
});

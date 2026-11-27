<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Http\ContentSecurityPolicy;
use Pollora\Hornet\Domain\Http\Context;
use Pollora\Hornet\Domain\Http\HeaderText;
use Pollora\Hornet\Domain\Http\Hsts;
use Pollora\Hornet\Domain\Http\RestAllowList;
use Pollora\Hornet\Domain\Http\SecurityHeaders;
use Pollora\Hornet\Domain\Journal\EventDetails;
use Pollora\Hornet\Domain\Journal\IpAnonymizer;
use Pollora\Hornet\Domain\Login\Attempts;
use Pollora\Hornet\Domain\Login\ThrottlePolicy;
use Pollora\Hornet\Domain\Network\Cidr;
use Pollora\Hornet\Domain\Network\TrustedProxies;
use Pollora\Hornet\Domain\Notification\Alert;
use Pollora\Hornet\Domain\Notification\Backoff;
use Pollora\Hornet\Domain\Notification\Delivery;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Domain\Server\ServerRules;

/*
 * Addresses, headers, the journal, the login throttle and the notifications, at their limits.
 */

it('reads what an HTTP status means, at the edges', function (int $status, bool $delivered, bool $retryable, string $reason): void {
    $delivery = Delivery::fromStatus($status);

    expect([$delivery->delivered, $delivery->retryable, $delivery->reason])->toBe([$delivered, $retryable, $reason]);
})->with([
    '199' => [199, false, false, 'The service refused the message (HTTP 199).'],
    '200' => [200, true, false, ''],
    '299' => [299, true, false, ''],
    '300' => [300, false, false, 'The service refused the message (HTTP 300).'],
    '408' => [408, false, true, 'The service asked to slow down (HTTP 408).'],
    '429' => [429, false, true, 'The service asked to slow down (HTTP 429).'],
    '499' => [499, false, false, 'The service refused the message (HTTP 499).'],
    '500' => [500, false, true, 'The service failed (HTTP 500).'],
]);

it('waits the first delay before anything failed', function (): void {
    expect((new Backoff)->delay(0))->toBe(60);
});

it('reads a stored alert and notification that lost fields', function (): void {
    $alert = Alert::fromArray([]);
    $notification = Notification::fromArray(['id' => 'n', 'created_at' => '2026-01-01T00:00:00+00:00', 'alerts' => [[]]]);

    expect([$alert->key, $alert->severity, $alert->title])->toBe(['', Severity::Info, ''])
        ->and([$notification?->siteName, $notification?->siteUrl])->toBe(['', '']);
});

it('writes the whole of a notification', function (): void {
    $notification = new Notification('n', 'Site', 'https://example.com', [new Alert('k', Severity::Low, 't')], new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

    expect($notification->toArray())->toBe([
        'id' => 'n', 'site_name' => 'Site', 'site_url' => 'https://example.com', 'created_at' => '2026-01-01T00:00:00+00:00',
        'alerts' => [['key' => 'k', 'severity' => 'low', 'title' => 't', 'details' => [], 'fix' => null]],
    ]);
});

it('takes a blank forwarding header for none', function (string $header): void {
    expect((new TrustedProxies)->resolve('203.0.113.1', $header)->forwardedIgnored)->toBeFalse();
})->with(['empty' => '', 'spaces' => '   ']);

it('reads a range written with spaces around it', function (): void {
    expect(Cidr::parse('s', " 10.0.0.0/8\n")->contains('10.1.2.3'))->toBeTrue();
});

it('compares the bits of a range exactly', function (string $range, string $address, bool $inside): void {
    expect(Cidr::parse('s', $range)->contains($address))->toBe($inside);
})->with([
    '/14 inside its second byte' => ['10.4.0.0/14', '10.5.0.1', true],
    '/14 outside' => ['10.4.0.0/14', '10.8.0.1', false],
    '/9 other half' => ['10.128.0.0/9', '10.1.0.0', false],
    '/17 other half' => ['10.0.128.0/17', '10.0.1.1', false],
]);

it('does not take any address that starts with zeros for an IPv4 one', function (): void {
    expect(IpAnonymizer::truncate('::1'))->toBe('::');
});

it('accepts an HSTS age at each limit, and refuses one past it', function (): void {
    expect((new Hsts(63072000))->maxAge)->toBe(63072000)
        ->and((new Hsts(31536000, true, true))->preload)->toBeTrue()
        ->and(fn (): Hsts => new Hsts(63072001))->toThrow(InvalidSettingsException::class);
});

it('takes a header value of 4096 characters, spaces around it taken off', function (): void {
    expect(HeaderText::check('s', str_repeat('a', 4096)))->toHaveLength(4096)
        ->and(HeaderText::check('s', '  a  '))->toBe('a');
});

it('refuses a report address with no host', function (): void {
    expect(fn (): ContentSecurityPolicy => new ContentSecurityPolicy(false, "default-src 'self'", 'https:/report'))->toThrow(InvalidSettingsException::class);
});

it('says which referrer policies it takes', function (): void {
    expect(fn (): SecurityHeaders => new SecurityHeaders(referrerPolicy: 'unsafe-url'))
        ->toThrow(InvalidSettingsException::class, 'must be one of no-referrer, same-origin, strict-origin, strict-origin-when-cross-origin, origin, origin-when-cross-origin.');
});

it('is active with any one header', function (Closure $headers): void {
    expect($headers()->isActive())->toBeTrue();
})->with([
    'HSTS' => [fn (): SecurityHeaders => new SecurityHeaders(hsts: new Hsts(300))],
    'a CSP' => [fn (): SecurityHeaders => new SecurityHeaders(csp: new ContentSecurityPolicy(false, "default-src 'self'"))],
]);

it('sends the permissions policy without the spaces around it', function (): void {
    expect((new SecurityHeaders(permissionsPolicy: ' camera=() '))->for(Context::Front, true))->toBe(['Permissions-Policy' => 'camera=()']);
});

it('ignores the case of the allowed REST prefixes', function (): void {
    $list = new RestAllowList(['Shop/Cart']);

    expect($list->allows('/shop/cart/items'))->toBeTrue()
        ->and($list->opens('SHOP'))->toBeTrue();
});

it('names a namespace when something in, above or below it is open', function (string $prefix, string $namespace, bool $opens): void {
    expect((new RestAllowList([$prefix]))->opens($namespace))->toBe($opens);
})->with([
    'below the namespace' => ['wc/store/v1/cart', 'wc/store/v1', true],
    'above the namespace' => ['wc', 'wc/store/v1', true],
    'a name that only starts the same' => ['wcx', 'wc', false],
    'a namespace that only starts the same' => ['wc', 'wcx/v1', false],
    'a namespace that is the end of the prefix' => ['abc/shop', 'shop', false],
    'a namespace that ends like the prefix' => ['shop', 'abc/shop', false],
]);

it('puts the shared headers after the rules for Apache', function (string $server): void {
    $rules = ServerRules::for($server, '/wp-content/uploads', true);
    $without = ServerRules::for($server, '/wp-content/uploads');

    expect($rules)->toStartWith($without)
        ->and(substr($rules, strlen($without)))->toContain('Header always set X-Content-Type-Options');
})->with(['apache', 'htaccess']);

it('says where an unknown setting was found, when it knows', function (): void {
    expect(InvalidSettingsException::unknownKey('a', '')->getMessage())->toBe('Unknown Hornet setting "a".')
        ->and(InvalidSettingsException::unknownKey('a', 'headers')->getMessage())->toBe('Unknown Hornet setting "a" in headers.');
});

it('keeps eight details and not a ninth', function (): void {
    $details = array_fill_keys(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'], 1);

    expect(array_keys(EventDetails::clean($details)))->toBe(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']);
});

it('keeps 800 bytes of details, and drops the last one past that', function (int $last, int $kept): void {
    $details = [];

    foreach (range(1, 6) as $i) {
        $details["k{$i}"] = str_repeat('v', 120);
    }

    $details['k7'] = str_repeat('v', $last);
    $clean = EventDetails::clean($details);

    expect(array_keys($clean))->toBe(array_slice(array_keys($details), 0, $kept));
})->with([
    'exactly 800' => [23, 7],
    '801' => [24, 6],
]);

it('keeps nothing of a text that is not UTF-8', function (): void {
    expect(EventDetails::text("ab\xff", 10))->toBe('');
});

describe('the login throttle', function (): void {
    $policy = new ThrottlePolicy(maxAttempts: 5, window: 900, lockout: 1);

    it('starts again when the window is over, to the second', function () use ($policy): void {
        expect($policy->failed(new Attempts(3, 100), 999)->failures)->toBe(4)
            ->and($policy->failed(new Attempts(3, 100), 1000)->failures)->toBe(1);
    });

    it('starts a window when there is none, and only then', function () use ($policy): void {
        expect($policy->failed(new Attempts(3, 0), 100)->failures)->toBe(1)
            ->and($policy->failed(new Attempts(2, 1), 100)->failures)->toBe(3);
    });

    it('counts without locking below the limit', function () use ($policy): void {
        expect($policy->failed(new Attempts(1, 50, 0, 2), 100)->toArray())->toBe(['failures' => 2, 'window_start' => 50, 'locked_until' => 0, 'lockouts' => 2]);
    });

    it('doubles the lockout ten times and no more', function (int $lockouts, int $seconds) use ($policy): void {
        expect($policy->failed(new Attempts(4, 50, 0, $lockouts), 100)->toArray())
            ->toBe(['failures' => 0, 'window_start' => 0, 'locked_until' => 100 + $seconds, 'lockouts' => $lockouts + 1]);
    })->with([[9, 512], [10, 1024], [11, 1024]]);

    it('has no time left once the lock is over', function () use ($policy): void {
        $locked = new Attempts(lockedUntil: 100);

        expect($policy->secondsLeft($locked, 100))->toBe(0)
            ->and($policy->secondsLeft($locked, 500))->toBe(0)
            ->and($policy->secondsLeft($locked, 99))->toBe(1);
    });
});

it('reads a negative stored count as nothing', function (): void {
    expect(Attempts::fromArray(['failures' => -1, 'lockouts' => -5])->toArray())->toBe(['failures' => 0, 'window_start' => 0, 'locked_until' => 0, 'lockouts' => 0]);
});

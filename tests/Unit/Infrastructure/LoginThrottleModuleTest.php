<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Login\Attempts;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Infrastructure\Config\LoginSettings;
use Pollora\Hornet\Infrastructure\Config\NetworkSettings;
use Pollora\Hornet\Infrastructure\Login\ClientAddress;
use Pollora\Hornet\Infrastructure\WordPress\LoginThrottleModule;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeWpError;
use Pollora\Hornet\Tests\Fixtures\InMemoryAttemptStore;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

/**
 * @param  array<mixed>  $login
 * @param  array<string, mixed>  $server
 * @return array{LoginThrottleModule, InMemoryAttemptStore, FakeClock, ArrayObject<int, string>, RecordingHooks}
 */
function throttle(array $login = [], array $server = ['REMOTE_ADDR' => '203.0.113.7'], array $network = []): array
{
    $settings = LoginSettings::fromArray(['throttle' => true, 'max_attempts' => 3, ...$login]);
    $proxies = NetworkSettings::fromArray($network);
    $store = new InMemoryAttemptStore;
    $clock = new FakeClock;
    $problems = new ArrayObject;
    $module = new LoginThrottleModule($settings, $store, $clock, static fn (): ?ClientIp => ClientAddress::fromServer($server, $proxies), static function (string $reason) use ($problems): void {
        $problems[] = $reason;
    }, 'salt');
    $hooks = new RecordingHooks;
    $module->register($hooks);

    return [$module, $store, $clock, $problems, $hooks];
}

it('attaches to the login late, so that WordPress cannot undo the lock', function (): void {
    [, , , , $hooks] = throttle();
    $priorities = array_column($hooks->registered, 'priority', 'hook');

    expect($priorities['authenticate'])->toBeGreaterThan(20)->and(array_column($hooks->registered, 'hook'))->toBe(['authenticate', 'wp_login_failed', 'application_password_failed_authentication', 'wp_authenticate_application_password_errors', 'wp_login']);
});

it('lets a visitor in until the failures reach the limit, then refuses even a valid user', function (): void {
    [$module] = throttle();

    foreach ([1, 2] as $_) {
        $module->failed();
        expect($module->gate('user'))->toBe('user');
    }

    $module->failed();
    $locked = $module->gate('a valid WP_User');

    expect($locked)->toBeInstanceOf(FakeWpError::class)->and($locked->get_error_code())->toBe('hornet_locked')
        ->and($locked->get_error_message())->toContain('Too many failed login attempts')->toContain('15 minute');
});

it('counts the failures of application passwords too', function (): void {
    [$module, , , , $hooks] = throttle();

    expect(array_column($hooks->registered, 'hook'))->toContain('application_password_failed_authentication');

    foreach ([1, 2, 3] as $_) {
        $hooks->callback('application_password_failed_authentication')();
    }

    expect($module->gate('user'))->toBeInstanceOf(FakeWpError::class);
});

it('locks per address: another visitor is not affected', function (): void {
    [$module, $store] = throttle();

    foreach ([1, 2, 3] as $_) {
        $module->failed();
    }

    expect(array_keys($store->records))->toHaveCount(1);

    [$other] = throttle([], ['REMOTE_ADDR' => '203.0.113.8']);

    expect($other->gate('user'))->toBe('user');
});

it('does not forget the failures after a success, and a lock ends with time', function (): void {
    [$module, , $clock] = throttle();

    $module->failed();
    $module->failed();
    $module->succeeded();

    // Two failures, a login of one's own, and the limit (3) is still one failure away: the login did not give the guesses back
    expect($module->gate('user'))->toBe('user');

    $module->failed();
    expect($module->gate('user'))->toBeInstanceOf(FakeWpError::class);

    $clock->advance('PT16M');

    expect($module->gate('user'))->toBe('user');
});

it('never counts nor locks an address on the allow list', function (): void {
    [$module, $store] = throttle(['allow_ips' => ['203.0.113.0/24']]);

    foreach (range(1, 10) as $_) {
        $module->failed();
    }

    expect($module->gate('user'))->toBe('user')->and($store->records)->toBe([]);
});

it('does nothing for a request with no visitor: the command line, the cron', function (): void {
    [$module, $store] = throttle([], []);

    $module->failed();
    $module->succeeded();

    expect($module->gate('user'))->toBe('user')->and($store->records)->toBe([]);
});

it('steps aside when every visitor has the same address, and says so', function (): void {
    [$module, $store, , $problems] = throttle([], ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9']);

    foreach (range(1, 10) as $_) {
        $module->failed();
    }

    // A forwarding header from an address that is not a trusted proxy: the connection is the proxy, which many share
    expect($module->gate('user'))->toBe('user')->and($store->records)->toBe([])->and($problems->getArrayCopy())->toContain('shared');
});

it('steps aside on a loopback connection', function (): void {
    [$module, $store, , $problems] = throttle([], ['REMOTE_ADDR' => '127.0.0.1']);

    $module->failed();

    expect($store->records)->toBe([])->and($problems->getArrayCopy())->toBe(['shared']);
});

it('works behind a trusted proxy: the visitor is the one the proxy reports', function (): void {
    $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.9'];
    [$module, $store, , $problems] = throttle([], $server, ['trusted_proxies' => ['10.0.0.0/8']]);

    foreach (range(1, 3) as $_) {
        $module->failed();
    }

    expect($module->gate('user'))->toBeInstanceOf(FakeWpError::class)->and($problems->getArrayCopy())->toBe([])
        ->and(array_keys($store->records))->toBe([(new ClientIp('198.51.100.9', '10.0.0.5'))->key('salt')]);
});

it('does not let a forwarding header change who is counted: an attacker cannot dodge the lock by adding one', function (): void {
    $key = (new ClientIp('203.0.113.7', '203.0.113.7'))->key('salt');
    [$module, $store, , $problems] = throttle([], ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9']);

    foreach (range(1, 3) as $_) {
        $module->failed();
    }

    // Counted under the connection, not under the address the header claims, and noted for the audit
    expect(array_keys($store->records))->toBe([$key])->and($module->gate('user'))->toBeInstanceOf(FakeWpError::class)->and($problems->getArrayCopy())->toBe(['forwarded-untrusted', 'forwarded-untrusted', 'forwarded-untrusted', 'forwarded-untrusted']);
});

it('keeps the memory of lockouts for a day on top of the lock, so that the next one is longer', function (): void {
    [$module, $store] = throttle();

    foreach (range(1, 3) as $_) {
        $module->failed();
    }

    $attempts = array_values($store->records)[0];

    expect($attempts)->toBeInstanceOf(Attempts::class)->and(array_values($store->keptFor)[0])->toBeGreaterThanOrEqual(900 + 86400);
});

it('stores a hash of the address, never the address', function (): void {
    [$module, $store] = throttle();
    $module->failed();

    expect(array_keys($store->records)[0])->toMatch('/\A[a-f0-9]{64}\z/')->not->toContain('203');
});

it('refuses even the right application password to a locked address, without counting that as a guess', function (): void {
    [$module, $store] = throttle();
    $open = new WP_Error;
    $module->gateApplicationPassword($open);

    expect($open->added)->toBe([]);

    foreach ([1, 2, 3] as $_) {
        $module->applicationPasswordFailed(new WP_Error('incorrect_password'));
    }

    $error = new WP_Error;
    $module->gateApplicationPassword($error);

    expect($error->added)->toHaveCount(1)->and($error->added[0][0])->toBe('hornet_locked');

    $before = serialize($store);
    $module->applicationPasswordFailed(new WP_Error('hornet_locked'));

    expect(serialize($store))->toBe($before);
});

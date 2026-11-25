<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Login\Unlocker;
use Pollora\Hornet\Domain\Login\Attempts;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Infrastructure\WpCli\UnlockCommand;
use Pollora\Hornet\Tests\Fixtures\InMemoryAttemptStore;
use Pollora\Hornet\Tests\Fixtures\RecordingOutput;

function lockedStore(): InMemoryAttemptStore
{
    $store = new InMemoryAttemptStore;
    $store->put((new ClientIp('203.0.113.9', '203.0.113.9'))->key('salt'), new Attempts(0, 0, time() + 900, 1), 900);
    $store->put((new ClientIp('203.0.113.10', '203.0.113.10'))->key('salt'), new Attempts(2, time(), 0, 0), 900);

    return $store;
}

it('lets one address back in, and only that one', function (): void {
    $store = lockedStore();
    $output = new RecordingOutput;
    (new UnlockCommand(fn () => new Unlocker($store, 'salt'), $output))(['203.0.113.9']);

    expect($output->successes)->toBe(['The address is let back in.'])->and($store->records)->toHaveCount(1)
        ->and(isset($store->records[(new ClientIp('203.0.113.9', '203.0.113.9'))->key('salt')]))->toBeFalse();
});

it('lets an address back in whichever way it is written', function (): void {
    $store = lockedStore();
    (new UnlockCommand(fn () => new Unlocker($store, 'salt'), new RecordingOutput))(['::ffff:203.0.113.9']);

    expect($store->records)->toHaveCount(1);
});

it('lets everybody back in, and says how many were locked', function (): void {
    $store = lockedStore();
    $output = new RecordingOutput;
    (new UnlockCommand(fn () => new Unlocker($store, 'salt'), $output))([], ['all' => true]);

    expect($output->successes[0])->toContain('1 were locked')->and($store->records)->toBe([]);
});

it('refuses what is not an address, and asks for one', function (): void {
    $command = new UnlockCommand(fn () => new Unlocker(lockedStore(), 'salt'), new RecordingOutput);

    expect(fn () => $command(['banana']))->toThrow(RuntimeException::class, 'not an IP address')
        ->and(fn () => $command([]))->toThrow(RuntimeException::class, 'Usage');
});

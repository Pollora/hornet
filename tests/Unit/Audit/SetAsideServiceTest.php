<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Audit\SetAsideService;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\Exceptions\SetAsideException;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\InMemorySetAsideStore;

/**
 * @param  list<array{string, string, array<string, mixed>}>  $events
 */
function asideService(InMemorySetAsideStore $store, array &$events = [], array $open = ['xml-rpc', 'https'], array $inFile = ['file-mods']): SetAsideService
{
    return new SetAsideService(
        $store,
        new FakeClock('2026-11-25 10:00:00'),
        static fn (): array => ['xml-rpc', 'https', 'file-mods', 'salts'],
        static fn (): array => $open,
        static fn (): array => $inFile,
        static function (string $type, string $subject, array $details) use (&$events): bool {
            $events[] = [$type, $subject, $details];

            return true;
        },
    );
}

it('sets a finding aside on a reason, remembers who and when, and says it in the journal', function (): void {
    $store = new InMemorySetAsideStore;
    $events = [];

    $aside = asideService($store, $events)->setAside('xml-rpc', 'Jetpack needs XML-RPC on this site.', 7, 'olivier');

    expect($aside)->toEqual(new SetAside('xml-rpc', 'Jetpack needs XML-RPC on this site.', 7, 'olivier', strtotime('2026-11-25 10:00:00 UTC')))
        ->and($store->all())->toHaveKey('xml-rpc')
        ->and($events)->toBe([['hornet.finding_set_aside', 'check:xml-rpc', ['reason' => 'Jetpack needs XML-RPC on this site.']]]);
});

it('asks for a reason: not nothing, not blank, not a word', function (string $reason): void {
    $store = new InMemorySetAsideStore;

    expect(fn () => asideService($store)->setAside('xml-rpc', $reason, 1, 'x'))->toThrow(SetAsideException::class, 'Say why')->and($store->all())->toBe([]);
})->with(['', '   ', 'ok', "\n\n\t", 'short']);

it('cleans the reason: no control character, a length', function (): void {
    $store = new InMemorySetAsideStore;

    $aside = asideService($store)->setAside('xml-rpc', "Because\x00 it\n is needed ".str_repeat('x', 500), 1, "bad\nlogin");

    expect(mb_strlen($aside->reason))->toBeLessThanOrEqual(300)->and($aside->reason)->not->toContain("\x00")->not->toContain("\n")->and($aside->login)->toBe('badlogin');
});

it('says which of the refusals it is', function (): void {
    $store = new InMemorySetAsideStore;
    $kind = static function (string $check) use ($store): string {
        try {
            asideService($store)->setAside($check, 'A reason that is long enough.', 1, 'x');
        } catch (SetAsideException $exception) {
            return $exception->kind;
        }

        return 'none';
    };

    expect($kind('nope'))->toBe('unknown')->and($kind('file-mods'))->toBe('locked')->and($kind('salts'))->toBe('not_open')->and($kind('xml-rpc'))->toBe('none');
});

it('lets the reason of a finding that is already set aside be changed, even when it is no longer open', function (): void {
    $store = new InMemorySetAsideStore(['salts' => new SetAside('salts', 'An old reason here.', 1, 'a', 1)]);

    $aside = asideService($store)->setAside('salts', 'A new and better reason.', 2, 'b');

    expect($aside->reason)->toBe('A new and better reason.')->and($store->all()['salts']->userId)->toBe(2);
});

it('brings a finding back, and says it', function (): void {
    $store = new InMemorySetAsideStore(['xml-rpc' => new SetAside('xml-rpc', 'A reason that is long enough.', 1, 'a', 1)]);
    $events = [];

    asideService($store, $events)->restore('xml-rpc');

    expect($store->all())->toBe([])->and($events)->toBe([['hornet.finding_restored', 'check:xml-rpc', []]]);
});

it('says nothing when there was nothing to bring back', function (): void {
    $events = [];

    asideService(new InMemorySetAsideStore, $events)->restore('xml-rpc');

    expect($events)->toBe([]);
});

it('does not bring back what the file sets aside, nor what does not exist', function (): void {
    expect(fn () => asideService(new InMemorySetAsideStore)->restore('file-mods'))->toThrow(SetAsideException::class, 'settings file')
        ->and(fn () => asideService(new InMemorySetAsideStore)->restore('nope'))->toThrow(SetAsideException::class, 'no check');
});

it('reads back what it stored, and leaves out what does not fit', function (): void {
    $aside = new SetAside('xml-rpc', 'A reason that is long enough.', 7, 'olivier', 1795600000);

    expect(SetAside::fromArray('xml-rpc', $aside->toArray()))->toEqual($aside)
        ->and(SetAside::fromArray('x', ['reason' => 1, 'user' => 1, 'login' => 'a', 'at' => 1]))->toBeNull()
        ->and(SetAside::fromArray('x', 'text'))->toBeNull()
        ->and(SetAside::fromArray('x', ['reason' => 'r', 'user' => '1', 'login' => 'a', 'at' => 1]))->toBeNull();
});

<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Journal\EventDetails;
use Pollora\Hornet\Domain\Journal\EventTypes;
use Pollora\Hornet\Domain\Journal\IpAnonymizer;
use Pollora\Hornet\Domain\Journal\JournalQuery;
use Pollora\Hornet\Domain\Journal\Source;

it('cuts an address so that it no longer points at one person', function (string $address, string $cut): void {
    expect(IpAnonymizer::truncate($address))->toBe($cut);
})->with([
    'IPv4' => ['203.0.113.77', '203.0.113.0'],
    'IPv4, last byte already zero' => ['10.1.2.0', '10.1.2.0'],
    'IPv6 keeps 48 bits' => ['2001:db8:1:2:3:4:5:6', '2001:db8:1::'],
    'IPv6 long' => ['2a01:e0a:1234:5678:9abc:def0:1234:5678', '2a01:e0a:1234::'],
    'IPv4 written the long way' => ['::ffff:203.0.113.77', '203.0.113.0'],
    'loopback' => ['127.0.0.1', '127.0.0.0'],
    'not an address' => ['not an ip', ''],
    'empty' => ['', ''],
    'an address with a port' => ['203.0.113.77:8080', ''],
    'a hostname' => ['example.com', ''],
]);

describe('the details of an event', function (): void {
    it('keeps flat text, numbers and booleans', function (): void {
        expect(EventDetails::clean(['from' => 'editor', 'to' => 'administrator', 'count' => 3, 'ok' => true]))->toBe(['from' => 'editor', 'to' => 'administrator', 'count' => 3, 'ok' => true]);
    });

    it('drops what looks like a secret by its name, whatever the case', function (): void {
        expect(EventDetails::clean(['password' => 'x', 'new_pass' => 'x', 'api_key' => 'x', 'SECRET' => 'x', 'auth_cookie' => 'x', 'nonce' => 'x', 'token' => 'x', 'hash' => 'x', 'salt' => 'x', 'name' => 'ci']))->toBe(['name' => 'ci']);
    });

    it('drops what is not flat, and a key that is not a plain name', function (): void {
        expect(EventDetails::clean(['list' => ['a'], 'obj' => new stdClass, 'null' => null, 'float' => 1.5, 'Bad Key' => 'x', '1x' => 'x', 'ok' => 'fine']))->toBe(['ok' => 'fine']);
    });

    it('cuts a value, and takes control and invisible characters out of it', function (): void {
        $clean = EventDetails::clean(['v' => "a\x00b\u{202E}c\n".str_repeat('x', 300)]);

        expect(mb_strlen($clean['v']))->toBeLessThanOrEqual(120)->and($clean['v'])->not->toContain("\x00")->not->toContain("\n")->not->toContain("\u{202E}")->toStartWith('abc');
    });

    it('keeps at most eight facts, and keeps the whole small', function (): void {
        $many = [];

        foreach (range(1, 20) as $n) {
            $many['k'.$n] = str_repeat('y', 100);
        }

        $clean = EventDetails::clean($many);

        expect(count($clean))->toBeLessThanOrEqual(8)->and(strlen((string) json_encode($clean)))->toBeLessThanOrEqual(800);
    });

    it('says one line of text', function (): void {
        expect(EventDetails::text("one\ntwo\tthree", 50))->toBe('onetwothree')->and(EventDetails::text('abcdef', 4))->toBe('abc…')->and(EventDetails::text('abc', 4))->toBe('abc');
    });
});

describe('the types', function (): void {
    it('is a short list with a group in front of each name', function (): void {
        expect(EventTypes::exists('login.failed'))->toBeTrue()->and(EventTypes::exists('login.failed '))->toBeFalse()->and(EventTypes::exists('whatever'))->toBeFalse()
            ->and(EventTypes::groups())->toBe(['login', 'user', 'plugin', 'theme', 'core', 'option', 'hornet'])
            ->and(count(EventTypes::all()))->toBe(count(array_unique(EventTypes::all())));

        foreach (EventTypes::all() as $type) {
            expect(strlen($type))->toBeLessThanOrEqual(40)->and($type)->toMatch('/^[a-z]+\.[a-z_]+$/D');
        }
    });
});

it('keeps a query inside its limits', function (): void {
    expect((new JournalQuery(limit: 5000))->limit)->toBe(200)->and((new JournalQuery(limit: 0))->limit)->toBe(1)->and((new JournalQuery(limit: -3))->limit)->toBe(1)
        ->and((new JournalQuery(offset: -5))->offset)->toBe(0)->and((new JournalQuery(source: Source::Cli))->source)->toBe(Source::Cli);
});

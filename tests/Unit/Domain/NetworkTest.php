<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Network\Cidr;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Domain\Network\TrustedProxies;

function range_(string $value): Cidr
{
    return Cidr::parse('s', $value);
}

describe('cidr', function (): void {
    it('matches one address, an IPv4 range and an IPv6 range', function (string $range, string $address, bool $inside): void {
        expect(range_($range)->contains($address))->toBe($inside);
    })->with([
        'the address itself' => ['203.0.113.7', '203.0.113.7', true],
        'another address' => ['203.0.113.7', '203.0.113.8', false],
        '/8' => ['10.0.0.0/8', '10.200.3.4', true],
        'outside /8' => ['10.0.0.0/8', '11.0.0.1', false],
        '/24 edge' => ['192.168.1.0/24', '192.168.1.255', true],
        'just outside /24' => ['192.168.1.0/24', '192.168.2.0', false],
        '/20 not on a byte' => ['172.16.0.0/20', '172.16.15.255', true],
        'just outside /20' => ['172.16.0.0/20', '172.16.16.0', false],
        '/0 is everything' => ['0.0.0.0/0', '8.8.8.8', true],
        '/32' => ['203.0.113.7/32', '203.0.113.7', true],
        'IPv6 range' => ['2001:db8::/32', '2001:db8:abcd::1', true],
        'outside the IPv6 range' => ['2001:db8::/32', '2001:db9::1', false],
        'IPv6 address' => ['::1', '::1', true],
        'IPv4 written as IPv6 is IPv4' => ['203.0.113.7', '::ffff:203.0.113.7', true],
        'an IPv4 range holds IPv4-mapped IPv6' => ['10.0.0.0/8', '::ffff:10.1.2.3', true],
        'IPv4 is not in an IPv6 range' => ['2001:db8::/32', '203.0.113.7', false],
        'not an address' => ['10.0.0.0/8', 'banana', false],
    ]);

    it('refuses what is not an address or a range', function (string $value): void {
        expect(fn () => Cidr::parse('network.trusted_proxies', $value))->toThrow(InvalidSettingsException::class, 'network.trusted_proxies');
    })->with(['banana', '10.0.0.0/33', '10.0.0.0/-1', '10.0.0.0/abc', '2001:db8::/129', '', '10.0.0.256', '10.0.0.0/8/9']);
});

describe('trusted proxies', function (): void {
    it('uses the connection when no forwarding header comes', function (): void {
        $ip = (new TrustedProxies([range_('10.0.0.0/8')]))->resolve('203.0.113.7', null);

        expect($ip->address)->toBe('203.0.113.7')->and($ip->forwardedIgnored)->toBeFalse();
    });

    it('does not believe a forwarding header from an address that is not a trusted proxy', function (): void {
        $ip = (new TrustedProxies([range_('10.0.0.0/8')]))->resolve('203.0.113.7', '1.2.3.4');

        expect($ip->address)->toBe('203.0.113.7')->and($ip->forwardedIgnored)->toBeTrue();
    });

    it('does not believe any header when no proxy is listed: the default', function (): void {
        $ip = (new TrustedProxies)->resolve('203.0.113.7', '1.2.3.4');

        expect($ip->address)->toBe('203.0.113.7')->and($ip->forwardedIgnored)->toBeTrue();
    });

    it('reads the visitor from a trusted proxy', function (): void {
        $ip = (new TrustedProxies([range_('10.0.0.5')]))->resolve('10.0.0.5', '198.51.100.9');

        expect($ip->address)->toBe('198.51.100.9')->and($ip->remote)->toBe('10.0.0.5')->and($ip->forwardedIgnored)->toBeFalse();
    });

    it('reads from the right and skips the trusted proxies, so a forged left part counts for nothing', function (): void {
        $proxies = new TrustedProxies([range_('10.0.0.0/8')]);

        expect($proxies->resolve('10.0.0.5', '6.6.6.6, 198.51.100.9, 10.0.0.9')->address)->toBe('198.51.100.9')
            ->and($proxies->resolve('10.0.0.5', '6.6.6.6, 198.51.100.9')->address)->toBe('198.51.100.9')
            ->and($proxies->resolve('10.0.0.5', '198.51.100.9,10.0.0.2 , 10.0.0.9')->address)->toBe('198.51.100.9');
    });

    it('falls back to the connection when every hop is a trusted proxy or none can be read', function (string $header): void {
        expect((new TrustedProxies([range_('10.0.0.0/8')]))->resolve('10.0.0.5', $header)->address)->toBe('10.0.0.5');
    })->with(['10.0.0.1, 10.0.0.2', 'not-an-ip', '', '   ', '198.51.100.9, junk']);

    it('reads IPv6 and the other headers it knows', function (): void {
        $proxies = new TrustedProxies([range_('2001:db8::/32')], 'CF-Connecting-IP');

        expect($proxies->resolve('2001:db8::1', '2a00:1450::1')->address)->toBe('2a00:1450::1')->and($proxies->header())->toBe('CF-Connecting-IP')
            ->and($proxies->isTrusted('2001:db8::5'))->toBeTrue()->and($proxies->isTrusted('2001:db9::5'))->toBeFalse();
    });
});

describe('client address', function (): void {
    it('knows an address that many visitors share: the proxy itself', function (string $address, bool $shared): void {
        expect((new ClientIp($address, $address))->isSharedAddress())->toBe($shared);
    })->with([
        'loopback' => ['127.0.0.1', true],
        'private 10' => ['10.1.2.3', true],
        'private 192.168' => ['192.168.1.5', true],
        'private 172.16' => ['172.20.0.2', true],
        'link local' => ['169.254.1.1', true],
        'IPv6 loopback' => ['::1', true],
        'IPv6 unique local' => ['fd00::5', true],
        'a public address' => ['203.0.113.7', false],
        'a public IPv6 address' => ['2a00:1450::1', false],
    ]);

    it('does not call shared a private visitor that a trusted proxy reported', function (): void {
        expect((new ClientIp('10.9.8.7', '10.0.0.5'))->isSharedAddress())->toBeFalse();
    });

    it('keys an address by a hash that is stable, salted and does not hold the address', function (): void {
        $ip = new ClientIp('203.0.113.7', '203.0.113.7');

        expect($ip->key('salt'))->toBe($ip->key('salt'))->and($ip->key('salt'))->not->toBe($ip->key('other'))
            ->and($ip->key('salt'))->toMatch('/\A[a-f0-9]{64}\z/')->and($ip->key('salt'))->not->toContain('203')
            ->and((new ClientIp('203.0.113.8', '203.0.113.8'))->key('salt'))->not->toBe($ip->key('salt'))
            // The same address written two ways is one key
            ->and((new ClientIp('::ffff:203.0.113.7', '::ffff:203.0.113.7'))->key('salt'))->toBe($ip->key('salt'));
    });
});

it('counts an IPv6 network as one address: the /64 an attacker owns is not a fresh start for each of its addresses', function (): void {
    $key = static fn (string $address): string => (new ClientIp($address, $address))->key('salt');

    expect($key('2001:db8:1:2::1'))->toBe($key('2001:db8:1:2:ffff:ffff:ffff:ffff'))
        ->and($key('2001:db8:1:2::1'))->not->toBe($key('2001:db8:1:3::1'))
        ->and($key('203.0.113.7'))->not->toBe($key('203.0.113.8'));
});

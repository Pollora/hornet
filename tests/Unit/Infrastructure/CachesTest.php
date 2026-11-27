<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Login\Attempts;
use Pollora\Hornet\Domain\Scan\PluginListing;
use Pollora\Hornet\Domain\Scan\Vulnerability;
use Pollora\Hornet\Infrastructure\Login\TransientAttemptStore;
use Pollora\Hornet\Infrastructure\WordPress\Journal\CacheWindowCounter;
use Pollora\Hornet\Infrastructure\WordPress\LoginDates;
use Pollora\Hornet\Infrastructure\WordPress\LoginDatesModule;
use Pollora\Hornet\Infrastructure\WordPress\LoginThrottleModule;
use Pollora\Hornet\Infrastructure\WordPress\ThrottleProblemNote;
use Pollora\Hornet\Infrastructure\WordPress\TransientAdvisoryCache;
use Pollora\Hornet\Infrastructure\WordPress\TransientPluginListingCache;
use Pollora\Hornet\Infrastructure\WordPress\TransientVulnerabilityCache;
use Pollora\Hornet\Tests\Fixtures\FakeSite;
use Pollora\Hornet\Tests\Fixtures\FakeWpUser;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

beforeEach(function (): void {
    $this->site = FakeSite::install();
});

/**
 * The one entry a cache wrote, by the prefix of its name.
 *
 * @return array{string, string}
 */
function onlyTransient(FakeSite $site, string $prefix): array
{
    expect($site->siteTransients)->toHaveCount(1);
    $name = (string) array_key_first($site->siteTransients);
    expect($name)->toStartWith($prefix);

    return [$name, $site->siteTransients[$name]];
}

describe('what api.osv.dev said', function (): void {
    it('is read back under a hashed name, for the time asked', function (): void {
        (new TransientAdvisoryCache)->put('vendor/package@1.0', ['a' => [1, 2]], 321);
        [$name, $stored] = onlyTransient($this->site, 'hornet_osv_');

        expect((new TransientAdvisoryCache)->get('vendor/package@1.0'))->toBe(['a' => [1, 2]])
            ->and((new TransientAdvisoryCache)->get('vendor/package@2.0'))->toBeNull()
            ->and($name)->toBe('hornet_osv_'.md5('vendor/package@1.0'))
            ->and($stored)->toBeString()
            ->and($this->site->lifetimes[$name])->toBe(321);
    });

    it('survives text that is not UTF-8', function (): void {
        (new TransientAdvisoryCache)->put('k', ['title' => "bad \xB1 byte"], 10);

        expect((new TransientAdvisoryCache)->get('k')['title'])->toContain('bad ');
    });

    it('is nothing when what is stored is not an array of JSON', function (mixed $stored): void {
        $this->site->siteTransients['hornet_osv_'.md5('k')] = $stored;

        expect((new TransientAdvisoryCache)->get('k'))->toBeNull();
    })->with(['not a string' => [['a']], 'broken' => ['{'], 'a number' => ['5']]);
});

describe('what the plugin directory said', function (): void {
    it('is read back with the moment it was asked, and kept for a week', function (): void {
        (new TransientPluginListingCache)->put('akismet', 1000, PluginListing::closed('Akismet', '2026-01-02', 'security-issue'));
        [$name] = onlyTransient($this->site, 'hornet_wporg_');
        $found = (new TransientPluginListingCache)->get('akismet');

        expect($found['at'])->toBe(1000)
            ->and($found['listing']->toArray())->toBe(PluginListing::closed('Akismet', '2026-01-02', 'security-issue')->toArray())
            ->and($this->site->lifetimes[$name])->toBe(604800)
            ->and((new TransientPluginListingCache)->get('hello'))->toBeNull();
    });

    it('is nothing when the entry is damaged: an unknown plugin must not pass for a listed one', function (mixed $stored): void {
        $this->site->siteTransients['hornet_wporg_'.md5('akismet')] = $stored;

        expect((new TransientPluginListingCache)->get('akismet'))->toBeNull();
    })->with([
        'not a string' => [false],
        'broken' => ['{'],
        'no time' => [json_encode(['listing' => ['status' => 'absent', 'name' => '']])],
        'a time as text' => [json_encode(['at' => '5', 'listing' => ['status' => 'absent', 'name' => '']])],
        'no listing' => [json_encode(['at' => 5])],
        'an unknown status' => [json_encode(['at' => 5, 'listing' => ['status' => 'x', 'name' => 'a']])],
    ]);
});

describe('what the vulnerability feed said', function (): void {
    it('is read back with its moment, and kept for four days', function (): void {
        $items = [new Vulnerability('CVE-1', 'One', Severity::High, '2.0', false, 'https://example.com/1'), new Vulnerability('CVE-2', 'Two', Severity::Low, null, true)];
        (new TransientVulnerabilityCache)->put('plugin:akismet:1.0', 42, $items);
        [$name] = onlyTransient($this->site, 'hornet_vuln_');
        $found = (new TransientVulnerabilityCache)->get('plugin:akismet:1.0');

        expect($found['at'])->toBe(42)
            ->and(array_map(static fn (Vulnerability $item): array => $item->toArray(), $found['items']))->toBe(array_map(static fn (Vulnerability $item): array => $item->toArray(), $items))
            ->and($this->site->lifetimes[$name])->toBe(345600);
    });

    it('is nothing, and not "no vulnerability", when the entry is damaged', function (mixed $stored): void {
        $this->site->siteTransients['hornet_vuln_'.md5('k')] = $stored;

        expect((new TransientVulnerabilityCache)->get('k'))->toBeNull();
    })->with([
        'not a string' => [[]],
        'broken' => ['{'],
        'a number' => ['5'],
        'no time' => ['{"items":[]}'],
        'a time as text' => ['{"at":"1","items":[]}'],
        'no items' => ['{"at":1}'],
        'items that are not a list' => ['{"at":1,"items":"none"}'],
    ]);

    it('leaves out one entry that cannot be read and keeps the others', function (): void {
        $this->site->siteTransients['hornet_vuln_'.md5('k')] = '{"at":1,"items":[{"id":"x"},{"id":"CVE-9","title":"T","severity":"high"}]}';

        expect(array_map(static fn (Vulnerability $item): string => $item->id, (new TransientVulnerabilityCache)->get('k')['items']))->toBe(['CVE-9']);
    });
});

describe('the failures of an address', function (): void {
    it('are kept in a transient without an object cache, and in the cache with one', function (bool $objectCache): void {
        $this->site->objectCache = $objectCache;
        $key = str_repeat('a', 64);
        $store = new TransientAttemptStore;
        $store->put($key, new Attempts(3, 100, 0, 1), 900);

        $name = $objectCache ? 'hornet-login:'.$key : 'hornet_lg_'.$key;

        expect($store->get($key)->toArray())->toBe((new Attempts(3, 100, 0, 1))->toArray())
            ->and($this->site->lifetimes[$name])->toBe(900)
            ->and($objectCache ? $this->site->transients : $this->site->cache)->toBe([])
            // Not locked: nothing to list
            ->and($this->site->options)->toBe([]);

        $store->forget($key);

        expect($store->get($key)->toArray())->toBe((new Attempts)->toArray());
    })->with([false, true]);

    it('lists a lockout, so that all of them can be lifted at once, and counts only those that still hold', function (bool $objectCache): void {
        $this->site->objectCache = $objectCache;
        $store = new TransientAttemptStore;
        $locked = str_repeat('a', 64);
        $past = str_repeat('b', 64);
        $store->put($locked, new Attempts(5, 1, time() + 600, 1), 900);
        $store->put($past, new Attempts(5, 1, time() - 1, 1), 900);
        // A lockout that is over, but still listed
        $this->site->options['hornet_lockouts'][$past] = time() - 10;

        expect($this->site->autoload['hornet_lockouts'])->toBeFalse()
            ->and($store->forgetAll())->toBe(1)
            ->and($store->get($locked)->failures)->toBe(0)
            ->and($store->get($past)->failures)->toBe(0)
            ->and($this->site->options)->not->toHaveKey('hornet_lockouts');
    })->with([false, true]);

    it('takes one lockout out of the list when it is lifted, and the list away with the last one', function (): void {
        $store = new TransientAttemptStore;
        $one = str_repeat('1', 64);
        $two = str_repeat('2', 64);
        $store->put($one, new Attempts(5, 1, time() + 600, 1), 900);
        $store->put($two, new Attempts(5, 1, time() + 900, 1), 900);
        $store->forget($one);

        expect(array_keys($this->site->options['hornet_lockouts']))->toBe([$two]);

        $store->forget($two);

        expect($this->site->options)->not->toHaveKey('hornet_lockouts');
    });

    it('drops from the list what is over when it adds to it', function (): void {
        $this->site->options['hornet_lockouts'] = [str_repeat('c', 64) => time() - 5];
        (new TransientAttemptStore)->put(str_repeat('d', 64), new Attempts(5, 1, time() + 600, 1), 900);

        expect(array_keys($this->site->options['hornet_lockouts']))->toBe([str_repeat('d', 64)]);
    });

    it('remembers five hundred lockouts at most, the ones that end last', function (): void {
        $index = [];

        foreach (range(1, 500) as $number) {
            $index[hash('sha256', (string) $number)] = time() + 100 + $number;
        }

        $this->site->options['hornet_lockouts'] = $index;
        $newest = str_repeat('e', 64);
        (new TransientAttemptStore)->put($newest, new Attempts(5, 1, time() + 5000, 1), 900);
        $kept = $this->site->options['hornet_lockouts'];

        expect($kept)->toHaveCount(500)->toHaveKey($newest)->not->toHaveKey(hash('sha256', '1'))->toHaveKey(hash('sha256', '2'));
    });

    it('does not touch a name that is not one of its keys, whatever the list says', function (mixed $stored): void {
        $this->site->options['hornet_lockouts'] = $stored;
        Functions\expect('delete_transient')->never();

        expect((new TransientAttemptStore)->forgetAll())->toBe(0);
    })->with([
        'not an array' => ['nonsense'],
        'a foreign key' => [['../x' => 9999999999]],
        'a time as text' => [[str_repeat('a', 64) => '9999999999']],
        'a number as key' => [[5 => 9999999999]],
    ]);
});

describe('the counter of a window', function (): void {
    it('counts in the object cache, with the window as lifetime', function (): void {
        $this->site->objectCache = true;
        $counter = new CacheWindowCounter;

        expect([$counter->hit('a', 600), $counter->hit('a', 600), $counter->hit('b', 600)])->toBe([1, 2, 1])
            ->and($this->site->lifetimes['hornet:hornet_jr_'.md5('a')])->toBe(600)
            ->and($this->site->transients)->toBe([]);
    });

    it('says one when the cache cannot count', function (): void {
        $this->site->objectCache = true;
        $this->site->cache['hornet:hornet_jr_'.md5('a')] = 'not a number';

        expect((new CacheWindowCounter)->hit('a', 600))->toBe(1);
    });

    it('counts in a transient without an object cache, and the window does not slide', function (): void {
        $counter = new CacheWindowCounter;
        $name = 'hornet_jr_'.md5('a');

        expect($counter->hit('a', 600))->toBe(1);

        [, $ends] = explode(':', $this->site->transients[$name]);

        expect((int) $ends)->toBeGreaterThan(time() + 590)->toBeLessThanOrEqual(time() + 600);

        // Half way through: the end stays where it was, and the lifetime is what is left
        $this->site->transients[$name] = '4:'.(time() + 100);

        expect($counter->hit('a', 600))->toBe(5)
            ->and($this->site->transients[$name])->toBe('5:'.(time() + 100))
            ->and($this->site->lifetimes[$name])->toBeGreaterThan(95)->toBeLessThanOrEqual(100);
    });

    it('asks for a lifetime of one second at least', function (): void {
        $name = 'hornet_jr_'.md5('a');
        $this->site->transients[$name] = '1:'.(time() - 50);
        (new CacheWindowCounter)->hit('a', 600);

        expect($this->site->lifetimes[$name])->toBe(1);
    });

    it('starts again on a value it cannot read', function (mixed $stored): void {
        $this->site->transients['hornet_jr_'.md5('a')] = $stored;

        expect((new CacheWindowCounter)->hit('a', 600))->toBe(1);
    })->with(['a number' => [7], 'text' => ['seven'], 'half of it' => ['7:'], 'a newline after' => ["7:1234567890\n"]]);
});

describe('the note of a throttle problem', function (): void {
    it('keeps the reason for a week', function (string $reason): void {
        $note = new ThrottleProblemNote;
        $note->record($reason);

        expect($note->read())->toBe($reason)->and($this->site->autoload[LoginThrottleModule::PROBLEM_OPTION])->toBeFalse();
    })->with(['shared', 'forwarded-untrusted']);

    it('is nothing after a week, with a reason it does not know, or when damaged', function (mixed $stored): void {
        $this->site->options[LoginThrottleModule::PROBLEM_OPTION] = $stored;

        expect((new ThrottleProblemNote)->read())->toBeNull();
    })->with([
        'old' => [fn () => ['reason' => 'shared', 'at' => time() - 604801]],
        'unknown reason' => [fn () => ['reason' => 'other', 'at' => time()]],
        'no time' => [['reason' => 'shared']],
        'not an array' => ['shared'],
    ]);

    it('writes the same reason once an hour at most, and a new reason at once', function (): void {
        $note = new ThrottleProblemNote;
        $recent = time() - 100;
        $this->site->options[LoginThrottleModule::PROBLEM_OPTION] = ['reason' => 'shared', 'at' => $recent];

        $note->record('shared');
        expect($this->site->options[LoginThrottleModule::PROBLEM_OPTION]['at'])->toBe($recent);

        $note->record('forwarded-untrusted');
        expect($note->read())->toBe('forwarded-untrusted');

        $this->site->options[LoginThrottleModule::PROBLEM_OPTION] = ['reason' => 'shared', 'at' => time() - 3700];
        $note->record('shared');
        expect($this->site->options[LoginThrottleModule::PROBLEM_OPTION]['at'])->toBeGreaterThan(time() - 5);
    });
});

describe('the last logins of the administrators', function (): void {
    it('notes since when it notes them, once, and the date of each login', function (): void {
        $dates = new LoginDates;

        expect($dates->since())->toBeNull()->and($dates->last(3))->toBeNull();

        $this->site->networkOptions[LoginDates::SINCE] = 1000;
        $dates->note(3);

        expect($dates->since())->toBe(1000)
            ->and($dates->last(3))->toBeGreaterThan(time() - 5)
            ->and($dates->last(4))->toBeNull();
    });

    it('starts the noting at the first one', function (): void {
        (new LoginDates)->start();

        expect((new LoginDates)->since())->toBeGreaterThan(time() - 5);
    });

    it('reads a date given as text, and none from nonsense', function (mixed $stored, ?int $expected): void {
        $this->site->networkOptions[LoginDates::SINCE] = $stored;
        $this->site->userMeta[3][LoginDates::META] = $stored;

        expect((new LoginDates)->since())->toBe($expected)->and((new LoginDates)->last(3))->toBe($expected);
    })->with([['1234', 1234], ['0', null], ['-5', null], ['yesterday', null], ['', null]]);

    it('notes the login of whoever manages the site, and of nobody else', function (bool $manages): void {
        Functions\when('user_can')->alias(static fn (mixed $user, string $capability): bool => $manages && $capability === 'manage_options');
        $module = new LoginDatesModule(new LoginDates);
        $module->loggedIn('ana', FakeWpUser::named(3, 'ana'));
        $module->loggedIn('ghost', null);

        expect((new LoginDates)->last(3) !== null)->toBe($manages)->and(array_keys($this->site->userMeta))->toBe($manages ? [3] : []);
    })->with([true, false]);

    it('attaches the login, the privacy tools and the start of the noting', function (): void {
        $hooks = new RecordingHooks;
        (new LoginDatesModule(new LoginDates))->register($hooks);

        expect(array_column($hooks->registered, 'hook'))->toBe(['wp_login', 'admin_init', 'admin_init', 'wp_privacy_personal_data_exporters', 'wp_privacy_personal_data_erasers']);

        $hooks->callback('admin_init')();

        expect((new LoginDates)->since())->not->toBeNull();
    });

    it('gives and erases the date through the privacy tools of WordPress, next to what others registered', function (): void {
        $user = FakeWpUser::named(3, 'ana');
        Functions\when('get_user_by')->alias(static fn (string $field, string $value): mixed => $field === 'email' && $value === 'ana@example.com' ? $user : false);
        $module = new LoginDatesModule(new LoginDates);
        $this->site->userMeta[3][LoginDates::META] = '86400';

        $exporters = $module->exporters(['other' => []]);
        $erasers = $module->erasers('not an array');

        expect(array_keys($exporters))->toBe(['other', 'hornet-last-login'])
            ->and(array_keys($erasers))->toBe(['hornet-last-login']);

        $export = $exporters['hornet-last-login']['callback']('ana@example.com');

        expect($export['done'])->toBeTrue()
            ->and($export['data'][0]['item_id'])->toBe('hornet-last-login-3')
            ->and($export['data'][0]['data'])->toBe([['name' => 'When', 'value' => '1970-01-02 00:00:00 UTC']])
            ->and($module->export('nobody@example.com'))->toBe(['data' => [], 'done' => true]);

        $erase = $erasers['hornet-last-login']['callback']('ana@example.com');

        expect($erase)->toBe(['items_removed' => true, 'items_retained' => false, 'messages' => [], 'done' => true])
            ->and($module->export('ana@example.com'))->toBe(['data' => [], 'done' => true])
            ->and($module->erase('ana@example.com')['items_removed'])->toBeFalse()
            ->and($module->erase('nobody@example.com')['items_removed'])->toBeFalse();
    });

    it('suggests a paragraph for the privacy policy', function (): void {
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\expect('wp_add_privacy_policy_content')->once()->withArgs(fn (string $who, string $text): bool => $who === 'Hornet (security)' && str_contains($text, 'last login of each administrator'));

        (new LoginDatesModule(new LoginDates))->policy();
    });
});

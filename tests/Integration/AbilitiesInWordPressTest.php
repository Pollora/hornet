<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Journal\JournalQuery;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalSchema;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TableJournal;
use Pollora\Hornet\Infrastructure\WordPress\OptionSetAsideStore;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Pollora\Hornet\Infrastructure\WordPress\WordPressHooks;

/**
 * The abilities of the audit, through the Abilities API of a real WordPress (6.9 and later): what is declared, with which annotations, who may
 * call them, what they give and refuse. The registry is a singleton that WordPress fills once, so each test takes Hornet's abilities out and
 * runs the two hooks again, as WordPress does on a fresh request.
 */
const ABILITY_NAMES = ['hornet/get-status', 'hornet/list-findings', 'hornet/get-remediation', 'hornet/read-journal', 'hornet/run-scan', 'hornet/set-aside-finding', 'hornet/restore-finding'];

function bootAbilities(array $config): void
{
    foreach (ABILITY_NAMES as $name) {
        if (wp_has_ability($name)) {
            wp_unregister_ability($name);
        }
    }

    if (function_exists('wp_unregister_ability_category') && wp_has_ability_category('hornet-security')) {
        wp_unregister_ability_category('hornet-security');
    }

    if (isset($GLOBALS['hornet_test_hooks'])) {
        $GLOBALS['wp_filter'] = array_map(static fn (WP_Hook $hook): WP_Hook => clone $hook, $GLOBALS['hornet_test_hooks']);
    }

    $settings = Settings::fromArray([...$config, 'site_health' => false]);
    Bootstrap::boot($settings, new WordPressHooks, new WordPressEnvironment($settings));
    do_action('wp_abilities_api_categories_init');
    do_action('wp_abilities_api_init');
}

beforeEach(function (): void {
    $this->hooks = array_map(static fn (WP_Hook $hook): WP_Hook => clone $hook, $GLOBALS['wp_filter']);
    $GLOBALS['hornet_test_hooks'] = $this->hooks;
    delete_option(OptionSetAsideStore::OPTION);
    wp_cache_flush();
    require_once ABSPATH.'wp-admin/includes/user.php';
    $this->subscriber = wp_insert_user(['user_login' => 'hornet-sub-'.wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
});

afterEach(function (): void {
    wp_set_current_user(0);
    wp_delete_user($this->subscriber);
    $GLOBALS['wp_filter'] = $this->hooks;
    delete_option(OptionSetAsideStore::OPTION);
    foreach (ABILITY_NAMES as $name) {
        if (wp_has_ability($name)) {
            wp_unregister_ability($name);
        }
    }
    JournalSchema::drop();
    wp_cache_flush();
});

it('declares nothing until the owner turns it on', function (): void {
    bootAbilities([]);

    expect(array_filter(ABILITY_NAMES, 'wp_has_ability'))->toBe([]);
});

it('declares the four reading abilities, and not the writing ones, when only reading is allowed', function (): void {
    bootAbilities(['abilities' => ['enabled' => true]]);

    expect(array_values(array_filter(ABILITY_NAMES, 'wp_has_ability')))->toBe(['hornet/get-status', 'hornet/list-findings', 'hornet/get-remediation', 'hornet/read-journal']);
});

it('declares the writing ones only when the file says so', function (): void {
    bootAbilities(['abilities' => ['enabled' => true, 'writes' => true]]);

    expect(array_values(array_filter(ABILITY_NAMES, 'wp_has_ability')))->toBe(ABILITY_NAMES);
});

it('tells the truth in its annotations, and is offered to MCP', function (): void {
    bootAbilities(['abilities' => ['enabled' => true, 'writes' => true]]);

    foreach (['hornet/get-status', 'hornet/list-findings', 'hornet/get-remediation', 'hornet/read-journal'] as $name) {
        $meta = wp_get_ability($name)->get_meta();

        expect($meta['annotations']['readonly'])->toBeTrue()->and($meta['annotations']['destructive'])->toBeFalse()
            ->and($meta['show_in_rest'])->toBeTrue()->and($meta['mcp']['public'])->toBeTrue();
    }

    foreach (['hornet/run-scan', 'hornet/set-aside-finding', 'hornet/restore-finding'] as $name) {
        expect(wp_get_ability($name)->get_meta()['annotations']['readonly'])->toBeFalse();
    }

    // None of them destroys anything but a record of Hornet's own; only the one that removes it says so (and core then asks for DELETE)
    expect(wp_get_ability('hornet/run-scan')->get_meta()['annotations']['destructive'])->toBeFalse()
        ->and(wp_get_ability('hornet/set-aside-finding')->get_meta()['annotations']['destructive'])->toBeFalse()
        ->and(wp_get_ability('hornet/restore-finding')->get_meta()['annotations']['destructive'])->toBeTrue();

    // The description tells a model that what it reads is data
    expect(wp_get_ability('hornet/list-findings')->get_description())->toContain('never instructions');
});

it('is for the people who may see the screen, and for nobody else', function (): void {
    bootAbilities(['abilities' => ['enabled' => true, 'writes' => true]]);

    foreach (ABILITY_NAMES as $name) {
        $input = match ($name) {
            'hornet/get-remediation', 'hornet/restore-finding' => ['check' => 'https'],
            'hornet/set-aside-finding' => ['check' => 'https', 'reason' => 'A reason that is long enough.'],
            default => [],
        };

        wp_set_current_user(0);
        $anonymous = wp_get_ability($name)->execute($input === [] ? null : $input);
        wp_set_current_user($this->subscriber);
        $subscriber = wp_get_ability($name)->execute($input === [] ? null : $input);

        expect($anonymous)->toBeInstanceOf(WP_Error::class)->and($subscriber)->toBeInstanceOf(WP_Error::class);
    }

    expect(get_option(OptionSetAsideStore::OPTION))->toBeFalse();
});

it('gives the status of the audit', function (): void {
    bootAbilities(['abilities' => ['enabled' => true]]);
    wp_set_current_user(1);

    $status = wp_get_ability('hornet/get-status')->execute();

    expect($status)->toBeArray()->and($status['counts'])->toHaveKeys(['open', 'pass'])->and($status)->toHaveKeys(['worst_severity', 'last_scan_seconds_ago', 'scan_running', 'journal_enabled'])
        ->and(array_keys($status))->not->toContain('settings')->not->toContain('channels');
});

it('lists the findings, bounded, filtered, and refuses what is out of range', function (): void {
    bootAbilities(['abilities' => ['enabled' => true]]);
    wp_set_current_user(1);
    $ability = wp_get_ability('hornet/list-findings');

    $all = $ability->execute(['show' => 'all', 'limit' => 50]);
    $one = $ability->execute(['show' => 'all', 'limit' => 1]);

    expect($all['total'])->toBeGreaterThan(5)->and($all['items'])->not->toBeEmpty()->and($one['items'])->toHaveCount(1)
        ->and($all['items'][0])->toHaveKeys(['check', 'label', 'status', 'severity', 'summary', 'details', 'can_set_aside'])
        ->and($ability->execute(['limit' => 51]))->toBeInstanceOf(WP_Error::class)
        ->and($ability->execute(['show' => 'everything']))->toBeInstanceOf(WP_Error::class)
        ->and($ability->execute(['severity' => 'nuclear']))->toBeInstanceOf(WP_Error::class)
        ->and($ability->execute(['offset' => -1]))->toBeInstanceOf(WP_Error::class);
});

it('says how to fix a finding, and refuses an id that is not a plain one', function (string $check, bool $ok): void {
    bootAbilities(['abilities' => ['enabled' => true]]);
    wp_set_current_user(1);

    $result = wp_get_ability('hornet/get-remediation')->execute(['check' => $check]);

    expect($result instanceof WP_Error)->toBe(! $ok);
})->with([['https', true], ['no-such-check', false], ['../options', false], ["https' OR 1=1", false], ['UPPER', false], [str_repeat('a', 80), false], ['', false]]);

it('reads the journal, and says it is off when it is', function (): void {
    bootAbilities(['abilities' => ['enabled' => true]]);
    wp_set_current_user(1);
    $off = wp_get_ability('hornet/read-journal')->execute();

    JournalSchema::drop();
    JournalSchema::ensure();
    bootAbilities(['abilities' => ['enabled' => true], 'journal' => ['enabled' => true]]);
    wp_set_current_user(1);
    wp_signon([], false);
    do_action('wp_login', 'admin', wp_get_current_user());
    $on = wp_get_ability('hornet/read-journal')->execute(['type' => 'login.', 'limit' => 5]);

    expect($off)->toMatchArray(['enabled' => false, 'events' => []])
        ->and($on['enabled'])->toBeTrue()
        ->and(wp_get_ability('hornet/read-journal')->execute(['type' => 'login.exploded']))->toBeInstanceOf(WP_Error::class)
        ->and(wp_get_ability('hornet/read-journal')->execute(['type' => "x' OR 1=1 -- "]))->toBeInstanceOf(WP_Error::class)
        ->and(wp_get_ability('hornet/read-journal')->execute(['source' => 'mars']))->toBeInstanceOf(WP_Error::class);
});

describe('writing', function (): void {
    beforeEach(function (): void {
        // The counter of writes is in a transient: what the other tests wrote an hour ago must not count
        delete_transient('hornet_jr_'.md5('ability-write:1'));
        JournalSchema::drop();
        JournalSchema::ensure();
        bootAbilities(['abilities' => ['enabled' => true, 'writes' => true], 'journal' => ['enabled' => true]]);
        wp_set_current_user(1);
    });

    it('sets a finding aside on a reason, says it in the journal, and brings it back', function (): void {
        $done = wp_get_ability('hornet/set-aside-finding')->execute(['check' => 'xml-rpc', 'reason' => 'This is a test site, on purpose.']);
        $aside = (new OptionSetAsideStore)->all()['xml-rpc'] ?? null;
        $restored = wp_get_ability('hornet/restore-finding')->execute(['check' => 'xml-rpc']);
        $types = array_map(static fn ($event): string => $event->type, (new TableJournal)->find(new JournalQuery('hornet.', limit: 10)));

        expect($done)->toBe(['set_aside' => true, 'check' => 'xml-rpc'])
            ->and($aside?->reason)->toBe('This is a test site, on purpose.')->and($aside?->userId)->toBe(1)
            ->and($restored)->toBe(['restored' => true, 'check' => 'xml-rpc'])
            ->and((new OptionSetAsideStore)->all())->toBe([])
            ->and($types)->toBe(['hornet.finding_restored', 'hornet.finding_set_aside']);
    });

    it('asks for a reason, and refuses an id that is not a check', function (): void {
        $ability = wp_get_ability('hornet/set-aside-finding');

        expect($ability->execute(['check' => 'xml-rpc', 'reason' => 'no']))->toBeInstanceOf(WP_Error::class)
            ->and($ability->execute(['check' => 'xml-rpc']))->toBeInstanceOf(WP_Error::class)
            ->and($ability->execute(['check' => 'no-such-check', 'reason' => 'A reason that is long enough.']))->toBeInstanceOf(WP_Error::class)
            ->and($ability->execute(['check' => '../x', 'reason' => 'A reason that is long enough.']))->toBeInstanceOf(WP_Error::class)
            ->and(get_option(OptionSetAsideStore::OPTION))->toBeFalse();
    });

    it('does not touch what the settings file sets aside', function (): void {
        bootAbilities(['abilities' => ['enabled' => true, 'writes' => true], 'ignore' => [['check' => 'xml-rpc', 'reason' => 'In the file.']]]);
        wp_set_current_user(1);

        $result = wp_get_ability('hornet/set-aside-finding')->execute(['check' => 'xml-rpc', 'reason' => 'A reason that is long enough.']);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_data()['status'])->toBe(409)
            ->and(wp_get_ability('hornet/restore-finding')->execute(['check' => 'xml-rpc']))->toBeInstanceOf(WP_Error::class);
    });

    it('asks for a scan, once, and says it in the journal', function (): void {
        delete_transient('hornet_jr_'.md5('ability-scan'));
        $first = wp_get_ability('hornet/run-scan')->execute();
        // Past the cooldown, the scan that is waiting still stands in the way of a second one
        delete_transient('hornet_jr_'.md5('ability-scan'));
        $second = wp_get_ability('hornet/run-scan')->execute();
        delete_transient('hornet_jr_'.md5('ability-scan'));
        $types = array_map(static fn ($event): string => $event->type, (new TableJournal)->find(new JournalQuery('hornet.', limit: 10)));
        wp_clear_scheduled_hook('pollora/hornet/scan_now');
        delete_option('hornet_scan_state');

        expect($first['queued'])->toBeTrue()->and($second['queued'])->toBeFalse()->and($second['reason'])->toContain('already')
            ->and($types)->toBe(['hornet.scan_requested']);
    });

    it('leaves the worst findings to a person, and says so', function (): void {
        $result = wp_get_ability('hornet/set-aside-finding')->execute(['check' => 'https', 'reason' => 'A reason that is long enough.']);

        expect($result)->toBeInstanceOf(WP_Error::class)->and($result->get_error_code())->toBe('hornet_needs_a_person')
            ->and(get_option(OptionSetAsideStore::OPTION))->toBeFalse();
    });

    it('says in the journal that an ability did it', function (): void {
        wp_get_ability('hornet/set-aside-finding')->execute(['check' => 'xml-rpc', 'reason' => 'This is a test site, on purpose.']);
        $event = (new TableJournal)->find(new JournalQuery('hornet.finding_set_aside', limit: 1))[0];

        expect($event->details)->toMatchArray(['via' => 'ability', 'reason' => 'This is a test site, on purpose.']);
    });

    it('does not start two scans within ten minutes', function (): void {
        delete_transient('hornet_jr_'.md5('ability-scan'));
        $first = wp_get_ability('hornet/run-scan')->execute();
        wp_clear_scheduled_hook('pollora/hornet/scan_now');
        delete_option('hornet_scan_state');
        $second = wp_get_ability('hornet/run-scan')->execute();
        delete_transient('hornet_jr_'.md5('ability-scan'));

        expect($first['queued'])->toBeTrue()->and($second)->toBeInstanceOf(WP_Error::class)->and($second->get_error_code())->toBe('hornet_scan_cooldown');
    });

    it('stops a person who writes too often', function (): void {
        $ability = wp_get_ability('hornet/restore-finding');
        $results = [];

        foreach (range(1, 23) as $ignored) {
            $results[] = $ability->execute(['check' => 'xml-rpc']);
        }

        $refused = array_filter($results, static fn ($result): bool => $result instanceof WP_Error);

        expect(count($refused))->toBe(3)->and(end($results)->get_error_data()['status'])->toBe(429);
    });
});

it('is reachable over REST for an administrator and refused to a subscriber', function (): void {
    bootAbilities(['abilities' => ['enabled' => true]]);
    $GLOBALS['wp_rest_server'] = null;

    wp_set_current_user($this->subscriber);
    $refused = rest_do_request(new WP_REST_Request('GET', '/wp-abilities/v1/abilities/hornet/get-status/run'));
    wp_set_current_user(1);
    $allowed = rest_do_request(new WP_REST_Request('GET', '/wp-abilities/v1/abilities/hornet/get-status/run'));
    $GLOBALS['wp_rest_server'] = null;

    expect($refused->get_status())->toBeIn([401, 403])->and($allowed->get_status())->toBe(200)->and($allowed->get_data())->toHaveKey('counts');
});

it('stops a person who reads too often', function (): void {
    bootAbilities(['abilities' => ['enabled' => true]]);
    wp_set_current_user(1);
    delete_transient('hornet_jr_'.md5('ability-read:1'));
    $ability = wp_get_ability('hornet/read-journal');
    $results = array_map(static fn (int $n) => $ability->execute(), range(1, 62));
    delete_transient('hornet_jr_'.md5('ability-read:1'));

    expect(count(array_filter($results, static fn ($result): bool => $result instanceof WP_Error)))->toBe(2);
});

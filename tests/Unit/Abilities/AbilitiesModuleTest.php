<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Scan\ScanState;
use Pollora\Hornet\Infrastructure\WordPress\Abilities\AbilitiesModule;
use Pollora\Hornet\Infrastructure\WordPress\OptionSetAsideStore;
use Pollora\Hornet\Tests\Fixtures\AdminSite;
use Pollora\Hornet\Tests\Fixtures\FakeEnvironment;
use Pollora\Hornet\Tests\Fixtures\FakeWpError;
use Pollora\Hornet\Tests\Fixtures\InMemoryWindowCounter;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

beforeEach(function (): void {
    $this->admin = new AdminSite;
    $this->counter = new InMemoryWindowCounter;
    $this->capabilities = ['manage_options'];
    Functions\when('current_user_can')->alias(fn (string $capability): bool => in_array($capability, $this->capabilities, true));
    $this->abilities = [];
    $this->categories = [];
    Functions\when('wp_has_ability')->justReturn(false);
    Functions\when('wp_has_ability_category')->justReturn(false);
    Functions\when('wp_register_ability')->alias(function (string $name, array $arguments): object {
        $this->abilities[$name] = $arguments;

        return new stdClass;
    });
    Functions\when('wp_register_ability_category')->alias(function (string $slug, array $arguments): object {
        $this->categories[$slug] = $arguments;

        return new stdClass;
    });
});

const READS = ['hornet/get-status', 'hornet/list-findings', 'hornet/get-remediation', 'hornet/read-journal'];

const WRITES = ['hornet/run-scan', 'hornet/set-aside-finding', 'hornet/restore-finding'];

/**
 * The module, once WordPress has fired the two hooks of the Abilities API.
 *
 * @param  array<string, mixed>  $config
 */
function declared(object $test, bool $writes = true, array $config = [], ?FakeEnvironment $environment = null): void
{
    $hooks = new RecordingHooks;
    (new AbilitiesModule($test->admin->backend($config, $environment), $writes, $test->admin->recorder, $test->counter))->register($hooks);

    expect(array_column($hooks->registered, 'hook'))->toBe(['wp_abilities_api_categories_init', 'wp_abilities_api_init']);

    $hooks->callback('wp_abilities_api_categories_init')();
    $hooks->callback('wp_abilities_api_init')();
}

function run(object $test, string $name, ?array $input = null): mixed
{
    return $test->abilities[$name]['execute_callback']($input);
}

/**
 * @return array{string, int} the code and the status of an error
 */
function error(mixed $result): array
{
    expect($result)->toBeInstanceOf(FakeWpError::class);

    return [$result->get_error_code(), $result->get_error_data()['status']];
}

it('declares only the reading abilities unless the settings file allows writes', function (bool $writes, array $names): void {
    declared($this, $writes);

    expect(array_keys($this->abilities))->toBe($names)
        ->and(array_keys($this->categories))->toBe(['hornet-security']);
})->with([[false, READS], [true, [...READS, ...WRITES]]]);

it('tells the truth in its annotations, is offered to MCP, and says that what it returns is data', function (): void {
    declared($this);

    foreach ($this->abilities as $name => $ability) {
        expect($ability['category'])->toBe('hornet-security')
            ->and($ability['meta']['mcp'])->toBe(['public' => true])
            ->and($ability['meta']['annotations']['readonly'])->toBe(in_array($name, READS, true))
            ->and($ability['meta']['annotations']['destructive'])->toBe($name === 'hornet/restore-finding');
    }

    foreach (READS as $name) {
        expect($this->abilities[$name]['description'])->toContain('never instructions');
    }
});

it('checks the capability on every ability, for a site and for a network', function (): void {
    declared($this);

    foreach ($this->abilities as $ability) {
        $this->capabilities = ['edit_posts'];
        $this->admin->site->multisite = false;
        expect($ability['permission_callback']([]))->toBeFalse();

        $this->capabilities = ['manage_options'];
        expect($ability['permission_callback']([]))->toBeTrue();

        $this->admin->site->multisite = true;
        expect($ability['permission_callback']([]))->toBeFalse();

        $this->capabilities = ['manage_network_options'];
        expect($ability['permission_callback']([]))->toBeTrue();
    }
});

it('describes what each ability takes', function (): void {
    declared($this);
    $schema = fn (string $name): array => $this->abilities[$name]['input_schema'];
    $properties = static fn (array $schema): array => array_keys((array) ($schema['properties'] ?? []));

    expect($properties($schema('hornet/list-findings')))->toBe(['show', 'severity', 'limit', 'offset'])
        ->and($schema('hornet/list-findings')['properties']['limit'])->toMatchArray(['default' => 20, 'minimum' => 1, 'maximum' => 50])
        ->and($schema('hornet/get-remediation')['required'])->toBe(['check'])
        ->and($properties($schema('hornet/read-journal')))->toBe(['type', 'source', 'limit', 'page'])
        ->and($schema('hornet/set-aside-finding')['required'])->toBe(['check', 'reason'])
        ->and($schema('hornet/restore-finding')['required'])->toBe(['check']);
});

it('gives the status of the audit', function (): void {
    declared($this);

    $status = run($this, 'hornet/get-status');

    expect($status['counts']['open'])->toBeGreaterThan(0)->and($status['worst_severity'])->toBe('high')->and($status['scan_running'])->toBeFalse()
        ->and($status)->not->toHaveKey('settings');
});

it('lists the findings that need attention, filtered and paged', function (): void {
    declared($this);

    $attention = run($this, 'hornet/list-findings');
    $high = run($this, 'hornet/list-findings', ['severity' => 'high', 'show' => 'open']);
    $page = run($this, 'hornet/list-findings', ['show' => 'all', 'limit' => 2, 'offset' => 1]);
    $all = run($this, 'hornet/list-findings', ['show' => 'all', 'limit' => 50]);
    $nonsense = run($this, 'hornet/list-findings', ['show' => 'everything']);

    expect(array_values(array_unique(array_column($attention['items'], 'status'))))->toBe(['open', 'unknown'])
        ->and(array_column($high['items'], 'check'))->toBe(['salts', 'https'])
        ->and(array_column($page['items'], 'check'))->toBe(array_slice(array_column($all['items'], 'check'), 1, 2))
        ->and($page['total'])->toBe($all['total'])
        ->and($nonsense['total'])->toBe($attention['total']);
});

it('says how to fix one finding, and refuses a check that is not one', function (): void {
    declared($this);

    expect(run($this, 'hornet/get-remediation', ['check' => 'salts']))->toMatchArray(['check' => 'salts', 'status' => 'open'])
        ->and(run($this, 'hornet/get-remediation', ['check' => 'salts'])['fix'])->not->toBeEmpty()
        ->and(error(run($this, 'hornet/get-remediation', ['check' => 'no-such'])))->toBe(['hornet_unknown_check', 404]);
});

it('takes a check id that is a plain slug and nothing else', function (string $ability, string $check): void {
    declared($this);

    expect(error(run($this, $ability, ['check' => $check, 'reason' => 'A long enough reason.'])))->toBe(['hornet_bad_check', 400])
        // Refused before it counted against the limit
        ->and($this->counter->counts)->toBe([]);
})->with(['hornet/get-remediation', 'hornet/set-aside-finding', 'hornet/restore-finding'])
    ->with(['', '../x', 'Salts', "salts\n", '-x', str_repeat('a', 61)]);

it('reads the journal with the filters it is given, and refuses a kind that is not one', function (): void {
    declared($this, config: ['journal' => ['enabled' => true]]);
    $this->admin->db->answers = ['2', [
        ['id' => '2', 'occurred_at' => '2026-11-25 10:00:00', 'type' => 'login.failed', 'source' => 'web', 'ip' => '203.0.113.55', 'details' => '[]'],
        ['id' => '1', 'occurred_at' => '2026-11-25 09:00:00', 'type' => 'login.success', 'source' => 'web', 'details' => '[]'],
    ]];

    $journal = run($this, 'hornet/read-journal', ['type' => 'login.', 'source' => 'web', 'page' => 1, 'limit' => 1]);

    expect($journal['enabled'])->toBeTrue()->and($journal['total'])->toBe(2)->and($journal['events'])->toHaveCount(1)
        ->and($journal['events'][0]['network'])->toBe('203.0.113.0')
        ->and($this->admin->db->ran[1])->toContain("type LIKE 'login.%' AND source = 'web'", 'LIMIT 1 OFFSET 0')
        ->and(error(run($this, 'hornet/read-journal', ['type' => 'login'])))->toBe(['hornet_bad_type', 400])
        ->and(error(run($this, 'hornet/read-journal', ['type' => 'nothing.'])))->toBe(['hornet_bad_type', 400]);
});

it('answers so many questions a minute and no more, each person on their own', function (string $ability, array $input): void {
    declared($this);
    $this->counter->counts['ability-read:7'] = AbilitiesModule::READS_PER_MINUTE - 1;

    expect(run($this, $ability, $input))->toBeArray()
        ->and(error(run($this, $ability, $input)))->toBe(['hornet_rate_limited', 429]);

    Functions\when('get_current_user_id')->justReturn(8);

    expect(run($this, $ability, $input))->toBeArray();
})->with([
    ['hornet/get-status', []],
    ['hornet/list-findings', []],
    ['hornet/get-remediation', ['check' => 'salts']],
    ['hornet/read-journal', []],
]);

it('allows so many changes an hour and no more', function (string $ability): void {
    declared($this);
    $this->counter->counts['ability-write:7'] = AbilitiesModule::WRITES_PER_HOUR;

    expect(error(run($this, $ability, ['check' => 'file-editor', 'reason' => 'A long enough reason.'])))->toBe(['hornet_rate_limited', 429])
        ->and($this->admin->scheduled)->toBe([])
        ->and((new OptionSetAsideStore)->all())->toBe([]);
})->with(WRITES);

it('allows the twentieth change of the hour', function (): void {
    declared($this);
    $this->counter->counts['ability-write:7'] = 19;

    expect(run($this, 'hornet/run-scan'))->toBe(['queued' => true, 'reason' => '']);
});

it('asks for a scan, says so in the journal, and not again within ten minutes', function (): void {
    declared($this);

    expect(run($this, 'hornet/run-scan'))->toBe(['queued' => true, 'reason' => ''])
        ->and($this->admin->spawned)->toBe(1)
        ->and(array_map(static fn (Event $event): array => [$event->type, $event->subject], $this->admin->journal->events))->toBe([['hornet.scan_requested', 'ability']])
        ->and(error(run($this, 'hornet/run-scan')))->toBe(['hornet_scan_cooldown', 429])
        ->and($this->admin->spawned)->toBe(1);
});

it('says why a scan is not queued, and writes nothing about it', function (): void {
    declared($this);
    $this->admin->state->save(new ScanState('running', null, time()));

    expect(run($this, 'hornet/run-scan'))->toBe(['queued' => false, 'reason' => 'A scan is already in progress.'])
        ->and($this->admin->journal->events)->toBe([]);
});

it('sets a low finding aside, said in the journal as done by an ability, and brings it back', function (): void {
    declared($this);

    expect(run($this, 'hornet/set-aside-finding', ['check' => 'file-editor', 'reason' => 'The editor is wanted here.']))->toBe(['set_aside' => true, 'check' => 'file-editor'])
        ->and((new OptionSetAsideStore)->all())->toHaveKey('file-editor')
        ->and($this->admin->journal->events[0]->details)->toBe(['reason' => 'The editor is wanted here.', 'via' => 'ability'])
        ->and(run($this, 'hornet/restore-finding', ['check' => 'file-editor']))->toBe(['restored' => true, 'check' => 'file-editor'])
        ->and((new OptionSetAsideStore)->all())->toBe([])
        ->and($this->admin->journal->events[1]->details)->toBe(['via' => 'ability']);
});

it('leaves the worst findings to a person', function (string $check, FakeEnvironment $environment): void {
    declared($this, environment: $environment);

    expect(error(run($this, 'hornet/set-aside-finding', ['check' => $check, 'reason' => 'Please hide this one.'])))->toBe(['hornet_needs_a_person', 403])
        ->and((new OptionSetAsideStore)->all())->toBe([]);
})->with([
    'high' => ['salts', fn () => new FakeEnvironment],
    'critical' => ['debug-mode', fn () => new FakeEnvironment(constants: ['WP_DEBUG' => true, 'WP_DEBUG_DISPLAY' => true])],
]);

it('says with the right status why a finding cannot be set aside or brought back', function (string $ability, array $input, string $code, int $status): void {
    declared($this, config: ['ignore' => [['check' => 'login-protection', 'reason' => 'From the file.']]]);

    expect(error(run($this, $ability, $input)))->toBe([$code, $status]);
})->with([
    ['hornet/set-aside-finding', ['check' => 'file-editor', 'reason' => 'no'], 'hornet_reason', 422],
    ['hornet/set-aside-finding', ['check' => 'nope', 'reason' => 'A long enough reason.'], 'hornet_unknown', 404],
    ['hornet/set-aside-finding', ['check' => 'login-protection', 'reason' => 'A long enough reason.'], 'hornet_locked', 409],
    ['hornet/restore-finding', ['check' => 'nope'], 'hornet_unknown', 404],
    ['hornet/restore-finding', ['check' => 'login-protection'], 'hornet_locked', 409],
]);

it('keeps a finding that was set aside from the screen as it is, until it is restored', function (): void {
    (new OptionSetAsideStore)->put(new SetAside('file-editor', 'From the screen.', 1, 'a', 1));
    declared($this);

    expect(run($this, 'hornet/list-findings', ['show' => 'ignored'])['items'][0])->toMatchArray(['check' => 'file-editor', 'set_aside_reason' => 'From the screen.']);
});

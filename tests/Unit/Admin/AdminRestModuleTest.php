<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Scan\ScanState;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminRestModule;
use Pollora\Hornet\Infrastructure\WordPress\OptionSetAsideStore;
use Pollora\Hornet\Tests\Fixtures\AdminSite;
use Pollora\Hornet\Tests\Fixtures\FakeRestRequest;
use Pollora\Hornet\Tests\Fixtures\FakeRestResponse;
use Pollora\Hornet\Tests\Fixtures\FakeWpdb;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

beforeEach(function (): void {
    $this->admin = new AdminSite;
    $this->can = ['manage_options'];
    Functions\when('current_user_can')->alias(fn (string $capability): bool => in_array($capability, $this->can, true));
    $this->routes = [];
    Functions\when('register_rest_route')->alias(function (string $namespace, string $route, array $arguments): bool {
        foreach (array_is_list($arguments) ? $arguments : [$arguments] as $one) {
            $this->routes[$namespace.$route][$one['methods']] = $one;
        }

        return true;
    });
});

function restModule(object $test, array $config = []): AdminRestModule
{
    return new AdminRestModule($test->admin->backend($config));
}

function request(array $params): FakeRestRequest
{
    $request = new FakeRestRequest;

    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    return $request;
}

const FINDING_ROUTE = 'hornet/v1/findings/(?P<check>[a-z0-9][a-z0-9-]{0,59})/ignore';

it('declares its routes on rest_api_init', function (): void {
    $hooks = new RecordingHooks;
    restModule($this)->register($hooks);

    expect(array_column($hooks->registered, 'hook'))->toBe(['rest_api_init', 'rest_index']);

    $hooks->callback('rest_api_init')();

    expect(array_map(array_keys(...), $this->routes))->toBe([
        'hornet/v1/status' => ['GET'],
        'hornet/v1/scan' => ['GET', 'POST'],
        'hornet/v1/journal' => ['GET'],
        FINDING_ROUTE => ['POST', 'DELETE'],
        'hornet/v1/notify/test' => ['POST'],
    ]);
});

it('opens no route to anyone who may not see the screen, and lists none in the index', function (): void {
    restModule($this)->routes();

    foreach ($this->routes as $methods) {
        foreach ($methods as $route) {
            $this->can = ['edit_posts', 'read'];
            expect($route['permission_callback']())->toBeFalse()->and($route['show_in_index'])->toBeFalse();

            $this->can = ['manage_options'];
            expect($route['permission_callback']())->toBeTrue();
        }
    }
});

it('accepts a journal filter only when it names a type, a group or a source that exists', function (string $argument, mixed $value, bool $valid): void {
    restModule($this)->routes();

    expect($this->routes['hornet/v1/journal']['GET']['args'][$argument]['validate_callback']($value))->toBe($valid);
})->with([
    ['type', '', true],
    ['type', 'login.failed', true],
    ['type', 'login.', true],
    ['type', 'login', false],
    ['type', 'login.nothing', false],
    ['type', 'nothing.', false],
    ['type', 12, false],
    ['source', '', true],
    ['source', 'cli', true],
    ['source', 'mars', false],
    ['source', ['cli'], false],
]);

it('bounds the numbers of the journal', function (): void {
    restModule($this)->routes();
    $args = $this->routes['hornet/v1/journal']['GET']['args'];

    expect($args['per_page'])->toMatchArray(['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100])
        ->and($args['page'])->toMatchArray(['default' => 1, 'minimum' => 1])
        ->and($args['user'])->toMatchArray(['default' => 0, 'minimum' => 0]);
});

it('takes a channel name that is a plain one', function (mixed $value, bool $valid): void {
    restModule($this)->routes();

    expect($this->routes['hornet/v1/notify/test']['POST']['args']['channel']['validate_callback']($value))->toBe($valid);
})->with([['team', true], ['Team.1_a-b', true], ['', false], ['a b', false], ["team\n", false], ['../x', false], [str_repeat('a', 65), false], [5, false]]);

it('takes the namespace out of the index for who may not use it, and leaves it to who may', function (): void {
    $index = static fn (): FakeRestResponse => new FakeRestResponse(['namespaces' => ['wp/v2', 'hornet/v1', 'other/v1'], 'name' => 'x']);
    $module = restModule($this);

    expect($module->hideFromIndex($index())->get_data()['namespaces'])->toBe(['wp/v2', 'hornet/v1', 'other/v1']);

    $this->can = [];

    expect($module->hideFromIndex($index())->get_data())->toBe(['namespaces' => ['wp/v2', 'other/v1'], 'name' => 'x'])
        ->and($module->hideFromIndex('not a response'))->toBe('not a response')
        ->and($module->hideFromIndex(new FakeRestResponse(['name' => 'x']))->get_data())->toBe(['name' => 'x']);
});

it('gives the status and the state of the scan', function (): void {
    $this->admin->state->save(new ScanState(ScanState::RUNNING, 'core-integrity', time()));
    $module = restModule($this);

    expect($module->status()->get_data())->toHaveKeys(['counts', 'findings', 'scan', 'settings'])
        ->and($module->scanState()->get_data())->toMatchArray(['status' => 'running', 'task' => 'core-integrity']);
});

it('answers 202 to a scan that is queued, and 409 to one asked while another is on its way', function (): void {
    $module = restModule($this);

    $first = $module->scan();
    $second = $module->scan();

    expect($first->get_status())->toBe(202)->and($first->get_data()['queued'])->toBeTrue()
        ->and($second->get_status())->toBe(409)->and($second->get_data()['queued'])->toBeFalse();
});

it('passes the filters of the journal on, as the types they are', function (): void {
    $this->admin->db->answers = ['1', []];
    $module = restModule($this, ['journal' => ['enabled' => true]]);

    $response = $module->journal(request(['type' => 'login.', 'source' => 'web', 'user' => '3', 'page' => '2', 'per_page' => '5']));

    expect($response->get_data()['page'])->toBe(2)
        ->and($this->admin->db->ran[1])->toContain("type LIKE 'login.%' AND source = 'web' AND user_id = 3", 'LIMIT 5 OFFSET 5');
});

it('sets a finding aside and brings it back', function (): void {
    $module = restModule($this);

    $aside = $module->setAside(request(['check' => 'salts', 'reason' => 'Rotated by the host.']));

    expect($aside->get_status())->toBe(200)->and($aside->get_data()['ok'])->toBeTrue()->and($aside->get_data()['aside']['reason'])->toBe('Rotated by the host.')
        ->and((new OptionSetAsideStore)->all())->toHaveKey('salts');

    $back = $module->restore(request(['check' => 'salts']));

    expect($back->get_data())->toBe(['ok' => true])->and((new OptionSetAsideStore)->all())->toBe([]);
});

it('answers a refusal with the status that says which', function (string $method, array $params, int $status, string $kind): void {
    $module = restModule($this, ['ignore' => [['check' => 'https', 'reason' => 'From the file.']]]);

    $response = $module->{$method}(request($params));

    expect($response->get_status())->toBe($status)
        ->and($response->get_data()['ok'])->toBeFalse()
        ->and($response->get_data()['kind'])->toBe($kind)
        ->and($response->get_data()['message'])->not->toBe('');
})->with([
    ['setAside', ['check' => 'nope', 'reason' => 'A long enough reason.'], 404, 'unknown'],
    ['setAside', ['check' => 'salts', 'reason' => 'no'], 422, 'reason'],
    ['setAside', ['check' => 'https', 'reason' => 'A long enough reason.'], 409, 'locked'],
    ['restore', ['check' => 'nope'], 404, 'unknown'],
    ['restore', ['check' => 'https'], 409, 'locked'],
]);

it('answers the test of a channel, and 404 for one there is not', function (mixed $channel, int $status): void {
    Functions\when('wp_mail')->justReturn(true);
    $module = restModule($this, ['notifications' => ['channels' => ['team' => ['type' => 'mail', 'to' => 'ops@example.com']]]]);

    $response = $module->test(request(['channel' => $channel]));

    expect($response->get_status())->toBe($status)->and($response->get_data()['status'])->toBe($status === 200 ? 'sent' : 'unknown');
})->with([['team', 200], ['other', 404], [null, 404]]);

it('answers an error, not an empty journal, when the database refuses the query', function (): void {
    $database = $GLOBALS['wpdb'] = new FakeWpdb;
    $database->failWith = 'refused';

    $response = restModule($this, ['journal' => ['enabled' => true]])->journal(request([]));

    expect($response->get_status())->toBe(500)
        ->and($response->get_data())->toBe(['code' => 'hornet_journal_unreadable']);
});

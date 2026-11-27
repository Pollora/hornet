<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Journal\EventTypes;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminPageModule;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminRestModule;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStore;
use Pollora\Hornet\Tests\Fixtures\AdminSite;
use Pollora\Hornet\Tests\Fixtures\FakeRestRequest;

beforeEach(function (): void {
    $this->admin = new AdminSite;
    Functions\when('current_user_can')->justReturn(true);
});

/** What was registered, with each callback told by the method it is. */
function mutWpPlain(mixed $value): mixed
{
    if ($value instanceof Closure) {
        $name = (new ReflectionFunction($value))->getName();

        return str_contains($name, '{closure') ? 'closure' : $name.'()';
    }

    return is_array($value) ? array_map(mutWpPlain(...), $value) : $value;
}

it('registers each REST route with its method, its callback, its permission and out of the index', function (): void {
    $routes = [];
    Functions\when('register_rest_route')->alias(function (string $namespace, string $route, array $arguments) use (&$routes): bool {
        $routes[$namespace.$route] = mutWpPlain($arguments);

        return true;
    });
    (new AdminRestModule($this->admin->backend()))->routes();

    $one = static fn (string $method, string $callback, array $args = []): array => ['methods' => $method, 'callback' => $callback.'()', 'permission_callback' => 'closure', 'show_in_index' => false, ...($args === [] ? [] : ['args' => $args])];

    expect($routes)->toBe([
        'hornet/v1/status' => $one('GET', 'status'),
        'hornet/v1/scan' => [$one('GET', 'scanState'), $one('POST', 'scan')],
        'hornet/v1/journal' => $one('GET', 'journal', [
            'type' => ['type' => 'string', 'default' => '', 'validate_callback' => 'closure'],
            'source' => ['type' => 'string', 'default' => '', 'validate_callback' => 'closure'],
            'user' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
            'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
            'per_page' => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
        ]),
        'hornet/v1/findings/(?P<check>[a-z0-9][a-z0-9-]{0,59})/ignore' => [
            $one('POST', 'setAside', ['reason' => ['required' => true, 'type' => 'string']]),
            $one('DELETE', 'restore'),
        ],
        'hornet/v1/notify/test' => $one('POST', 'test', ['channel' => ['required' => true, 'type' => 'string', 'validate_callback' => 'closure']]),
    ]);
});

it('says why a finding could not be set aside, and why a channel is not there', function (): void {
    $module = new AdminRestModule($this->admin->backend());
    $request = new FakeRestRequest;
    $request->set_param('check', 'nope');
    $request->set_param('channel', 'nope');

    expect($module->restore($request)->get_data())->toBe(['ok' => false, 'message' => 'There is no check "nope".', 'kind' => 'unknown'])
        ->and($module->test($request)->get_data())->toBe(['status' => 'unknown', 'reason' => 'There is no such channel.']);
});

it('says that the journal is off, with the kinds it would hold', function (): void {
    expect($this->admin->backend()->journal([]))->toBe(['enabled' => false, 'events' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'types' => EventTypes::groups()]);
});

it('asks the journal for what the screen filters, whatever the type of the values', function (array $params, string $where, string $page): void {
    $this->admin->db->answers = ['3', []];

    $result = $this->admin->backend(['journal' => ['enabled' => true]])->journal($params);

    expect($this->admin->db->ran[1])->toBe('SELECT * FROM `wp_hornet_journal` '.$where.'ORDER BY occurred_at DESC, id DESC '.$page)
        ->and($result['page'])->toBe((int) substr($page, strrpos($page, ' ') + 1) === 0 ? 1 : 2);
})->with([
    'nothing' => [[], ' ', 'LIMIT 25 OFFSET 0'],
    'empty filters' => [['type' => '', 'source' => '', 'user' => 0], ' ', 'LIMIT 25 OFFSET 0'],
    'numbers as text' => [['per_page' => '10', 'page' => '2', 'user' => '1'], 'WHERE user_id = 1 ', 'LIMIT 10 OFFSET 10'],
    'a type' => [['type' => 'login.failed', 'user' => -1], "WHERE type = 'login.failed' ", 'LIMIT 25 OFFSET 0'],
]);

it('calls a scan overdue once it is more than 75 seconds late and nothing answered it', function (int $late, ?int $answered, bool $overdue): void {
    $tries = 0;

    // The clock must not tick between the two readings of it
    do {
        $now = time();
        $this->admin->nextScan = $now - $late;

        if ($answered !== null) {
            (new OptionScanStore)->save(new ScanSnapshot(new DateTimeImmutable('@'.($now - $late + $answered)), []));
        }

        $result = $this->admin->backend()->status()['scan']['overdue'];
    } while (time() !== $now && ++$tries < 5);

    expect($result)->toBe($overdue);
})->with([
    '75 seconds' => [75, null, false],
    '76 seconds' => [76, null, true],
    'a scan at the very second it was due' => [200, 0, false],
    'a scan just before' => [200, -1, true],
]);

it('gives the status and the reason of a test message', function (): void {
    Functions\when('is_email')->justReturn(true);
    Functions\when('wp_mail')->justReturn(true);
    $backend = $this->admin->backend(['notifications' => ['channels' => ['team' => ['type' => 'mail', 'to' => 'ops@example.com']]]]);

    expect($backend->testChannel('team'))->toBe(['status' => 'sent', 'reason' => '']);
});

function mutWpPage(string $assets): AdminPageModule
{
    return new AdminPageModule(false, $assets, static function (): never {
        throw new RuntimeException('ended');
    });
}

it('prints the page exactly: a title for screen readers, the container, the word for those without JavaScript', function (): void {
    ob_start();
    mutWpPage('/nowhere')->render();

    expect((string) ob_get_clean())->toBe('<div class="wrap"><h1 class="screen-reader-text">Security</h1><hr class="wp-header-end"><div id="hornet-admin"></div><noscript><p>This screen needs JavaScript. The same information is given by `wp hornet status` and `wp hornet scan`.</p></noscript></div>');
});

it('names the missing scripts once each, in a notice before the container, and ignores empty names', function (): void {
    $assets = sys_get_temp_dir().'/hornet-mut-assets-'.bin2hex(random_bytes(4));
    mkdir($assets);
    file_put_contents($assets.'/index.asset.php', "<?php return ['dependencies' => [5, '', 'wp-here', 'wp-gone', 'wp-lost'], 'version' => '1'];");
    file_put_contents($assets.'/index.js', '');
    Functions\when('add_management_page')->justReturn('the-screen');
    $asked = [];
    Functions\when('wp_script_is')->alias(function (string $handle) use (&$asked): bool {
        $asked[] = $handle;

        return $handle === 'wp-here';
    });

    try {
        $module = mutWpPage($assets);
        $module->menu();
        $module->enqueue('the-screen');
        ob_start();
        $module->render();
        $html = (string) ob_get_clean();
    } finally {
        unlink($assets.'/index.asset.php');
        unlink($assets.'/index.js');
        rmdir($assets);
    }

    expect($asked)->toBe(['wp-here', 'wp-gone', 'wp-lost'])
        ->and($html)->toContain('<hr class="wp-header-end"><div class="notice notice-error inline"><p>This screen needs scripts that this version of WordPress does not have (wp-gone, wp-lost). Use `wp hornet status` and `wp hornet scan`: they give the same information.</p></div><div id="hornet-admin"></div>');
});

it('refuses with a message and no title of its own', function (): void {
    Functions\when('current_user_can')->justReturn(false);
    $refusals = [];
    Functions\when('wp_die')->alias(function (string $message, string $title, array $args) use (&$refusals): never {
        $refusals[] = [$message, $title, $args];

        throw new RuntimeException('died');
    });
    $_GET['hornet-asset'] = 'index.js';

    try {
        expect(fn () => mutWpPage('/nowhere')->render())->toThrow(RuntimeException::class, 'died')
            ->and(fn () => mutWpPage('/nowhere')->send())->toThrow(RuntimeException::class, 'died');
    } finally {
        unset($_GET['hornet-asset']);
    }

    expect($refusals)->toBe([
        ['You are not allowed to see this page.', '', ['response' => 403]],
        ['You are not allowed to see this file.', '', ['response' => 403]],
    ]);
});

it('ends the request after saying that there is no such file', function (): void {
    Functions\when('status_header')->justReturn(null);
    $_GET['hornet-asset'] = 'nothing';

    try {
        expect(fn () => mutWpPage('/nowhere')->send())->toThrow(RuntimeException::class, 'ended');
    } finally {
        unset($_GET['hornet-asset']);
    }
});

<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminPageModule;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;
use Pollora\Hornet\Tests\Fixtures\SentHeaders;

/**
 * Stands for `exit`: the module ends the request after a file, or after a refusal.
 */
final class RequestEnded extends RuntimeException {}

/**
 * `wp_die()` ends the request too.
 */
final class RequestDied extends RuntimeException
{
    public function __construct(string $message, public readonly mixed $args)
    {
        parent::__construct($message);
    }
}

beforeEach(function (): void {
    $this->can = true;
    Functions\when('is_multisite')->justReturn(false);
    Functions\when('current_user_can')->alias(fn (): bool => $this->can);
    Functions\when('wp_die')->alias(static fn (string $message, string $title, array $args): never => throw new RequestDied($message, $args));
    $this->statuses = [];
    Functions\when('status_header')->alias(function (int $status): void {
        $this->statuses[] = $status;
    });
    $this->root = sys_get_temp_dir().'/hornet-assets-'.bin2hex(random_bytes(4));
    $this->assets = $this->root.'/assets/admin';
    mkdir($this->assets, 0o777, true);
    $this->enqueued = [];
    Functions\when('wp_script_is')->alias(static fn (string $handle): bool => $handle !== 'wp-missing');
    Functions\when('wp_enqueue_script')->alias(function (string $handle, string $url, array $dependencies, string $version, bool $footer): void {
        $this->enqueued['script'] = [$handle, $url, $dependencies, $version, $footer];
    });
    Functions\when('wp_enqueue_style')->alias(function (string $handle, string $url = '', array $dependencies = [], string $version = ''): void {
        $this->enqueued['style:'.$handle] = [$url, $dependencies, $version];
    });
    Functions\when('wp_set_script_translations')->alias(function (string $handle, string $domain, string $path): void {
        $this->enqueued['translations'] = [$handle, $domain, $path];
    });
    $this->rtl = false;
    Functions\when('is_rtl')->alias(fn (): bool => $this->rtl);
    Functions\when('admin_url')->alias(static fn (string $path): string => 'https://example.com/wp-admin/'.$path);
    Functions\when('network_admin_url')->alias(static fn (string $path): string => 'https://example.com/wp-admin/network/'.$path);
    Functions\when('add_query_arg')->alias(static fn (array $args, string $url): string => $url.'?'.http_build_query($args));
});

afterEach(function (): void {
    unset($_GET['hornet-asset']);
    array_map(unlink(...), glob($this->assets.'/*') ?: []);
    rmdir($this->assets);
    rmdir($this->root.'/assets');
    rmdir($this->root);
});

function adminPage(object $test, bool $network = false): AdminPageModule
{
    return new AdminPageModule($network, $test->assets, static fn (): never => throw new RequestEnded);
}

/**
 * The module with its menu built, so that it knows its screen.
 */
function builtPage(object $test, bool $network = false): AdminPageModule
{
    Functions\when('add_management_page')->justReturn('tools_page_hornet-security');
    Functions\when('add_submenu_page')->justReturn('settings_page_hornet-security');
    $module = adminPage($test, $network);
    $module->menu();

    return $module;
}

function build(object $test, array $dependencies = ['wp-element'], mixed $version = 'abc'): void
{
    file_put_contents($test->assets.'/index.asset.php', '<?php return '.var_export(['dependencies' => $dependencies, 'version' => $version], true).';');
    file_put_contents($test->assets.'/index.js', 'console.log(1)');
    file_put_contents($test->assets.'/style-index.css', 'a{}');
    file_put_contents($test->assets.'/style-index-rtl.css', 'b{}');
}

it('adds one entry under Tools, or under the settings of the network for the super administrators', function (bool $network, string $hook, string $function, string $capability): void {
    $hooks = new RecordingHooks;
    adminPage($this, $network)->register($hooks);

    expect(array_column($hooks->registered, 'hook'))->toBe([$hook, 'admin_enqueue_scripts', 'admin_init']);

    $added = null;
    Functions\when($function)->alias(function (mixed ...$arguments) use (&$added): string {
        $added = $arguments;

        return 'the-screen';
    });
    $module = adminPage($this, $network);

    expect($module->screen())->toBeNull();

    $module->menu();

    expect($module->screen())->toBe('the-screen')
        ->and($added)->toContain($capability, AdminPageModule::PAGE, 'Security')
        ->and($network ? $added[0] : null)->toBe($network ? 'settings.php' : null);
})->with([
    [false, 'admin_menu', 'add_management_page', 'manage_options'],
    [true, 'network_admin_menu', 'add_submenu_page', 'manage_network_options'],
]);

it('has no screen when WordPress refuses the entry', function (): void {
    Functions\when('add_management_page')->justReturn(false);
    $module = adminPage($this);
    $module->menu();

    expect($module->screen())->toBeNull();
});

it('prints a container for the script, and what to do without JavaScript', function (): void {
    ob_start();
    adminPage($this)->render();
    $html = (string) ob_get_clean();

    expect($html)->toContain('<div id="hornet-admin"></div>', '<noscript>', 'wp hornet status')->not->toContain('notice-error');
});

it('refuses the page to who may not see it', function (): void {
    $this->can = false;
    ob_start();

    try {
        adminPage($this)->render();
        $this->fail('The page was printed.');
    } catch (RequestDied $requestDied) {
        expect($requestDied->args)->toBe(['response' => 403]);
    } finally {
        expect(ob_get_clean())->toBe('');
    }
});

it('enqueues the script and its style on its own screen only, with the version of the build', function (bool $rtl, string $style): void {
    build($this);
    $this->rtl = $rtl;
    $module = builtPage($this);
    $module->enqueue('index.php');

    expect($this->enqueued)->toBe([]);

    $module->enqueue('tools_page_hornet-security');

    expect($this->enqueued['script'])->toBe(['hornet-admin', 'https://example.com/wp-admin/admin.php?hornet-asset=index.js&ver=abc', ['wp-element'], 'abc', true])
        ->and($this->enqueued['style:hornet-admin'])->toBe(['https://example.com/wp-admin/admin.php?hornet-asset='.$style.'&ver=abc', ['wp-components'], 'abc'])
        ->and($this->enqueued)->toHaveKey('style:wp-components')
        ->and($this->enqueued['translations'])->toBe(['hornet-admin', 'pollora-hornet', $this->root.'/languages']);
})->with([[false, 'style-index.css'], [true, 'style-index-rtl.css']]);

it('serves the files from the network administration on a network, and says dev for a build with no version', function (): void {
    build($this, ['wp-element', 5], null);
    unlink($this->assets.'/style-index.css');
    builtPage($this, true)->enqueue('settings_page_hornet-security');

    expect($this->enqueued['script'][1])->toBe('https://example.com/wp-admin/network/admin.php?hornet-asset=index.js&ver=dev')
        ->and($this->enqueued['script'][2])->toBe(['wp-element'])
        ->and($this->enqueued)->not->toHaveKey('style:hornet-admin');
});

it('enqueues nothing without a menu, or without a complete build', function (string $missing): void {
    build($this);

    if ($missing === 'menu') {
        $module = adminPage($this);
    } else {
        $module = builtPage($this);
        unlink($this->assets.'/'.$missing);
    }

    $module->enqueue('tools_page_hornet-security');

    expect($this->enqueued)->toBe([]);
})->with(['menu', 'index.asset.php', 'index.js']);

it('enqueues nothing and says which scripts WordPress does not have', function (): void {
    build($this, ['wp-element', 'wp-missing']);
    $module = builtPage($this);
    $module->enqueue('tools_page_hornet-security');

    ob_start();
    $module->render();
    $html = (string) ob_get_clean();

    expect($this->enqueued)->toBe([])->and($html)->toContain('notice-error', 'wp-missing');
});

it('does nothing on a request that asks for no file', function (mixed $asked): void {
    if ($asked !== null) {
        $_GET['hornet-asset'] = $asked;
    }

    adminPage($this)->send();

    expect(SentHeaders::$sent)->toBe([])->and($this->statuses)->toBe([]);
})->with([null, [['index.js']]]);

it('sends a compiled file with its type, to a person who may see the screen', function (string $file, string $type): void {
    build($this);
    $_GET['hornet-asset'] = $file;
    ob_start();

    try {
        adminPage($this)->send();
        $this->fail('The request went on.');
    } catch (RequestEnded) {
        $body = (string) ob_get_clean();
    }

    expect($body)->toBe(file_get_contents($this->assets.'/'.$file))
        ->and(array_column(SentHeaders::$sent, 0))->toBe(['Content-Type: '.$type, 'X-Content-Type-Options: nosniff', 'Cache-Control: private, max-age=31536000, immutable', 'Content-Length: '.strlen($body)])
        ->and($this->statuses)->toBe([]);
})->with([
    ['index.js', 'application/javascript; charset=UTF-8'],
    ['style-index.css', 'text/css; charset=UTF-8'],
    ['style-index-rtl.css', 'text/css; charset=UTF-8'],
]);

it('answers 404 for any other name, whatever file it points at', function (string $asked): void {
    build($this);

    if ($asked === 'missing') {
        unlink($this->assets.'/index.js');
        $asked = 'index.js';
    }

    $_GET['hornet-asset'] = $asked;
    ob_start();

    try {
        adminPage($this)->send();
    } catch (RequestEnded) {
    }

    expect(ob_get_clean())->toBe('')->and($this->statuses)->toBe([404])->and(SentHeaders::$sent)->toBe([]);
})->with(['index.asset.php', '../admin/index.js', './index.js', 'INDEX.JS', '', '/etc/passwd', 'missing']);

it('refuses a file to who may not see the screen', function (): void {
    build($this);
    $this->can = false;
    $_GET['hornet-asset'] = 'index.js';
    ob_start();

    try {
        adminPage($this)->send();
        $this->fail('The file was sent.');
    } catch (RequestDied $requestDied) {
        expect($requestDied->args)->toBe(['response' => 403]);
    } finally {
        expect(ob_get_clean())->toBe('')->and(SentHeaders::$sent)->toBe([]);
    }
});

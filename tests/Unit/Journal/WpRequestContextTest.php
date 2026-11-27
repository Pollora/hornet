<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Infrastructure\WordPress\Journal\WpRequestContext;
use Pollora\Hornet\Tests\Fixtures\FakeWpUser;

function requestContext(bool $cron = false, bool $ajax = false, bool $admin = false, ?ClientIp $client = new ClientIp('203.0.113.9', '10.0.0.1')): WpRequestContext
{
    Functions\when('wp_doing_cron')->justReturn($cron);
    Functions\when('wp_doing_ajax')->justReturn($ajax);
    Functions\when('is_admin')->justReturn($admin);

    return new WpRequestContext(static fn (): ?ClientIp => $client);
}

it('says where the request came from, an AJAX call before the administration it runs in', function (bool $cron, bool $ajax, bool $admin, Source $source): void {
    expect(requestContext($cron, $ajax, $admin)->source())->toBe($source);
})->with([
    'the cron' => [true, false, false, Source::Cron],
    'AJAX' => [false, true, true, Source::Ajax],
    'the administration' => [false, false, true, Source::Admin],
    'the public site' => [false, false, false, Source::Web],
]);

it('gives the address of a visitor, and none for the cron', function (): void {
    expect(requestContext()->ip())->toBe('203.0.113.9')
        ->and(requestContext(client: null)->ip())->toBe('')
        ->and(requestContext(cron: true)->ip())->toBe('');
});

it('names who is logged in, and nobody otherwise', function (): void {
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('wp_get_current_user')->justReturn(FakeWpUser::named(3, 'ana'));

    expect(requestContext()->userId())->toBe(3)->and(requestContext()->userLogin())->toBe('ana');

    $nobody = FakeWpUser::named(0, 'leftover');
    Functions\when('wp_get_current_user')->justReturn($nobody);

    expect(requestContext()->userLogin())->toBe('');
});

/*
 * The constants of WordPress cannot be undefined again: each is tried in a process of its own.
 */
it('knows the command line, XML-RPC and the REST API by their constant, and gives the command line no address', function (string $constant, string $source, string $address): void {
    $script = <<<'PHP'
    require getcwd().'/vendor/autoload.php';
    define($argv[1], true);
    function wp_doing_cron() { return false; }
    function wp_doing_ajax() { return false; }
    function is_admin() { return false; }
    $context = new Pollora\Hornet\Infrastructure\WordPress\Journal\WpRequestContext(static fn () => new Pollora\Hornet\Domain\Network\ClientIp('203.0.113.9', '10.0.0.1'));
    echo $context->source()->value, '|', $context->ip();
    PHP;

    $output = [];
    exec(sprintf('cd %s && %s -r %s %s 2>&1', escapeshellarg(dirname(__DIR__, 3)), escapeshellarg(PHP_BINARY), escapeshellarg($script), escapeshellarg($constant)), $output, $code);

    expect($code)->toBe(0)->and(implode("\n", $output))->toBe($source.'|'.$address);
})->with([
    ['WP_CLI', 'cli', ''],
    ['XMLRPC_REQUEST', 'xmlrpc', '203.0.113.9'],
    ['REST_REQUEST', 'rest', '203.0.113.9'],
]);

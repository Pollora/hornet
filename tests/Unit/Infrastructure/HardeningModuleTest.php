<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Infrastructure\Config\HardeningSettings;
use Pollora\Hornet\Infrastructure\WordPress\HardeningModule;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

function hardening(array $on): RecordingHooks
{
    $hooks = new RecordingHooks;
    (new HardeningModule(HardeningSettings::fromArray($on)))->register($hooks);

    return $hooks;
}

it('attaches nothing when nothing is turned on', function (): void {
    expect(hardening([])->registered)->toBe([]);
});

it('closes XML-RPC: no method, no login, no pingback header', function (): void {
    $hooks = hardening(['xmlrpc' => true]);

    expect($hooks->callback('xmlrpc_enabled')())->toBeFalse()
        ->and($hooks->callback('xmlrpc_methods')())->toBe([])
        ->and($hooks->callback('wp_headers')(['X-Pingback' => 'https://x/xmlrpc.php', 'Link' => 'a']))->toBe(['Link' => 'a']);
});

it('takes the RSD and Windows Live Writer links out of the head', function (): void {
    Functions\expect('remove_action')->once()->with('wp_head', 'rsd_link');
    Functions\expect('remove_action')->once()->with('wp_head', 'wlwmanifest_link');

    hardening(['xmlrpc' => true])->callback('init')();
});

it('hides the version', function (): void {
    $hooks = hardening(['hide_version' => true]);
    Functions\expect('remove_action')->once()->with('wp_head', 'wp_generator');

    expect($hooks->callback('the_generator')())->toBe('');
    $hooks->callback('init')();
});

it('refuses the editor capabilities to everyone, and only those', function (): void {
    $callback = hardening(['file_editor' => true])->callback('map_meta_cap');

    expect($callback(['edit_plugins'], 'edit_plugins'))->toBe(['do_not_allow'])
        ->and($callback(['edit_themes'], 'edit_themes'))->toBe(['do_not_allow'])
        ->and($callback(['edit_files'], 'edit_files'))->toBe(['do_not_allow'])
        ->and($callback(['manage_options'], 'manage_options'))->toBe(['manage_options'])
        ->and($callback(['install_plugins'], 'install_plugins'))->toBe(['install_plugins']);
});

describe('user enumeration', function (): void {
    it('runs before redirect_canonical, which would show the login in the address', function (): void {
        $registered = array_values(array_filter(hardening(['user_enumeration' => true])->registered, static fn (array $entry): bool => $entry['hook'] === 'template_redirect'));

        expect($registered)->toHaveCount(1)->and($registered[0]['priority'])->toBeLessThan(10);
    });

    it('takes the user routes away from a visitor and leaves them to a logged-in user', function (): void {
        $callback = hardening(['user_enumeration' => true])->callback('rest_endpoints');
        $routes = ['/wp/v2/users' => [], '/wp/v2/users/(?P<id>[\d]+)' => [], '/wp/v2/users/me' => [], '/wp/v2/posts' => []];

        Functions\when('is_user_logged_in')->justReturn(false);
        expect(array_keys($callback($routes)))->toBe(['/wp/v2/posts']);

        Functions\when('is_user_logged_in')->justReturn(true);
        expect($callback($routes))->toBe($routes);
    });

    it('drops the users sitemap and nothing else', function (): void {
        $callback = hardening(['user_enumeration' => true])->callback('wp_sitemaps_add_provider');

        expect($callback('provider', 'users'))->toBeFalse()->and($callback('provider', 'posts'))->toBe('provider');
    });

    it('drops the author of an oEmbed answer', function (): void {
        expect(hardening(['user_enumeration' => true])->callback('oembed_response_data')(['title' => 'T', 'author_name' => 'admin', 'author_url' => 'u']))->toBe(['title' => 'T']);
    });

    it('answers a numeric ?author= from a visitor with a 404', function (): void {
        $_GET['author'] = '1';
        $query = new class
        {
            public bool $notFound = false;

            public function set_404(): void
            {
                $this->notFound = true;
            }
        };
        $GLOBALS['wp_query'] = $query;
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\expect('status_header')->once()->with(404);
        Functions\expect('nocache_headers')->once();

        HardeningModule::refuseAuthorQuery();

        expect($query->notFound)->toBeTrue();
        unset($_GET['author'], $GLOBALS['wp_query']);
    });

    it('leaves a logged-in user, a missing query and a non-numeric one alone', function (mixed $author, bool $loggedIn): void {
        $_GET = $author === null ? [] : ['author' => $author];
        Functions\when('is_user_logged_in')->justReturn($loggedIn);
        Functions\expect('status_header')->never();

        HardeningModule::refuseAuthorQuery();

        $_GET = [];
    })->with([
        'logged in' => ['1', true],
        'no author' => [null, false],
        'a name' => ['admin', false],
        'a number and more' => ['1 OR 1=1', false],
        'an array' => [['1'], false],
    ]);
});

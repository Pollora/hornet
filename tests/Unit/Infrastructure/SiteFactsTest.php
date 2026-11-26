<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Infrastructure\WordPress\ErrorDisplayModule;
use Pollora\Hornet\Infrastructure\WordPress\ErrorDisplayNote;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;

it('gives no role by registration when it is closed, or on a network', function (): void {
    Functions\when('is_multisite')->justReturn(false);
    Functions\when('get_option')->justReturn(false);

    expect((new WordPressEnvironment)->registrationRole())->toBeNull();

    Functions\when('is_multisite')->justReturn(true);

    expect((new WordPressEnvironment)->registrationRole())->toBeNull();
});

it('gives what the default role may do when anybody can register', function (): void {
    Functions\when('is_multisite')->justReturn(false);
    Functions\when('get_option')->alias(static fn (string $name): mixed => $name === 'default_role' ? 'editor' : '1');
    Functions\when('get_role')->justReturn((object) ['capabilities' => ['read' => true, 'edit_pages' => true, 'manage_options' => false]]);

    expect((new WordPressEnvironment)->registrationRole())->toBe(['role' => 'editor', 'capabilities' => ['read', 'edit_pages']]);

    Functions\when('get_role')->justReturn(null);

    expect((new WordPressEnvironment)->registrationRole())->toBeNull();
});

it('says application passwords are off when the site does not offer them, wherever it is asked from', function (): void {
    Functions\when('home_url')->justReturn('http://example.test');
    Functions\when('wp_get_environment_type')->justReturn('production');
    Functions\when('apply_filters')->returnArg(2);

    expect((new WordPressEnvironment)->administratorApplicationPasswords())->toBeNull();
});

it('keeps what the web server does with errors, and writes only a change', function (): void {
    $stored = null;
    $writes = 0;
    Functions\when('get_site_option')->alias(static function () use (&$stored): mixed {
        return $stored ?? false;
    });
    Functions\when('update_site_option')->alias(static function (string $name, string $value) use (&$stored, &$writes): bool {
        $stored = $value;
        $writes++;

        return true;
    });
    $note = new ErrorDisplayNote;

    expect($note->read())->toBeNull();

    $note->record(false);
    expect($note->read())->toBeNull();

    $note->record('1');
    $note->record('On');
    expect($note->read())->toBeTrue()->and($writes)->toBe(1);

    $note->record('0');
    expect($note->read())->toBeFalse()->and($writes)->toBe(2);

    $note->record('stderr');
    expect($note->read())->toBeFalse()->and($writes)->toBe(2);
});

it('looks on a page of the dashboard only: WordPress hides errors itself on Ajax and REST requests', function (string $which, int $writes): void {
    $count = 0;
    Functions\when('wp_doing_ajax')->justReturn($which === 'ajax');
    Functions\when('wp_doing_cron')->justReturn($which === 'cron');
    Functions\when('wp_is_json_request')->justReturn($which === 'json');
    Functions\when('get_site_option')->justReturn(false);
    Functions\when('update_site_option')->alias(static function () use (&$count): bool {
        $count++;

        return true;
    });

    $hooks = new RecordingHooks;
    $module = new ErrorDisplayModule(new ErrorDisplayNote);
    $module->register($hooks);
    $module->look();

    expect($count)->toBe($writes);
})->with([['page', 1], ['ajax', 0], ['cron', 0], ['json', 0]]);

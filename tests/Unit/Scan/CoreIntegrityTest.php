<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Scan\CoreIntegrity;
use Pollora\Hornet\Domain\Scan\CoreVerdict;
use Pollora\Hornet\Tests\Fixtures\ArrayFileSystem;

/** A small core: the files WordPress ships, with contents that are made up. */
function core(): ArrayFileSystem
{
    return new ArrayFileSystem([
        'index.php' => 'index',
        'wp-load.php' => 'load',
        'wp-config.php' => 'config',
        'wp-admin/index.php' => 'admin',
        'wp-includes/l10n.php' => 'l10n',
        'wp-includes/version.php' => 'version',
        'wp-content/plugins/index.php' => 'silence',
    ]);
}

/**
 * @param  array<string, string>  $official
 * @param  list<string>  $allowed
 * @param  array<string, string>  $pinned
 */
function inspect(ArrayFileSystem $files, ?array $official = null, array $allowed = [], array $pinned = []): CoreVerdict
{
    $verdict = (new CoreIntegrity($files))->inspect($official ?? core()->official(), $allowed, $pinned);
    assert($verdict instanceof CoreVerdict);

    return $verdict;
}

it('finds nothing in a core that is what WordPress.org published', function (): void {
    $verdict = inspect(core());

    expect($verdict->isClean())->toBeTrue()
        // wp-content and wp-config.php are the site's own: left out of the count
        ->and($verdict->checked)->toBe(5);
});

it('reports a file that differs, a file that is gone and a file that cannot be read', function (): void {
    $files = core();
    $files->files['wp-includes/version.php'] = 'changed';
    unset($files->files['wp-load.php']);
    $files->files['wp-admin/index.php'] = null;

    $verdict = inspect($files, core()->official());

    expect($verdict->modified)->toBe(['wp-includes/version.php'])
        ->and($verdict->missing)->toBe(['wp-load.php'])
        ->and($verdict->unreadable)->toBe(['wp-admin/index.php'])
        ->and($verdict->isClean())->toBeFalse();
});

it('reports a file that WordPress does not ship in wp-admin, wp-includes or as a script in the root', function (): void {
    $files = core();
    $files->files['wp-includes/shell.php'] = 'x';
    $files->files['wp-admin/css/readme.txt'] = 'x';
    $files->files['wso.php'] = 'x';

    $verdict = inspect($files, core()->official());

    expect($verdict->unexpected)->toBe(['wso.php', 'wp-admin/css/readme.txt', 'wp-includes/shell.php'])
        ->and($verdict->unexpectedCode())->toBe(['wso.php', 'wp-includes/shell.php']);
});

it('leaves alone what the site and the host put next to the core', function (): void {
    $files = core();
    $official = core()->official();
    $files->files['robots.txt'] = 'x';
    $files->files['.htaccess'] = 'x';
    $files->files['google1234.html'] = 'x';
    $files->files['wp-includes/error_log'] = 'x';
    $files->files['wp-admin/.DS_Store'] = 'x';
    $files->files['wp-content/uploads/shell.php'] = 'x';

    expect(inspect($files, $official)->unexpected)->toBe([]);
});

it('lets a file differ when it is allowed, and pins it', function (): void {
    $files = core();
    $official = core()->official();
    $files->files['wp-includes/l10n.php'] = 'patched';

    $verdict = inspect($files, $official, ['wp-includes/l10n.php']);

    expect($verdict->isClean())->toBeTrue()
        ->and($verdict->patched)->toBe(['wp-includes/l10n.php' => md5('patched')]);
});

it('reports an allowed file that changed again after it was pinned, the best place to hide in', function (): void {
    $files = core();
    $official = core()->official();
    $files->files['wp-includes/l10n.php'] = 'patched then backdoored';

    $verdict = inspect($files, $official, ['wp-includes/l10n.php'], ['wp-includes/l10n.php' => md5('patched')]);

    expect($verdict->changedSincePinned)->toBe(['wp-includes/l10n.php'])
        ->and($verdict->isClean())->toBeFalse();
});

it('does not allow a file that is not on the list', function (): void {
    $files = core();
    $files->files['wp-includes/version.php'] = 'changed';

    expect(inspect($files, core()->official(), ['wp-includes/l10n.php'])->modified)->toBe(['wp-includes/version.php']);
});

it('does not know when the install cannot be listed', function (): void {
    foreach (['', 'wp-admin', 'wp-includes'] as $directory) {
        $files = core();
        $files->unlistable = [$directory];

        expect((new CoreIntegrity($files))->inspect(core()->official(), [], []))->toBeNull();
    }
});

it('never trusts a path or a hash of the official list', function (): void {
    $official = [
        ...core()->official(),
        '../wp-config.php' => md5('x'),
        '/etc/passwd' => md5('x'),
        'wp-includes//double.php' => md5('x'),
        "wp-includes/nul\0.php" => md5('x'),
        'wp-includes/back\\slash.php' => md5('x'),
        'wp-includes/short.php' => 'not a hash',
        'wp-content/plugins/hello.php' => md5('x'),
    ];

    $verdict = inspect(core(), $official);

    expect($verdict->checked)->toBe(5)
        ->and($verdict->missing)->toBe([]);
});

it('tells a plain relative path from the others', function (string $path, bool $safe): void {
    expect(CoreIntegrity::isSafe($path))->toBe($safe);
})->with([
    ['wp-includes/l10n.php', true],
    ['index.php', true],
    ['', false],
    ['/index.php', false],
    ['a/../b', false],
    ['a/./b', false],
    ['a//b', false],
    ['a\\b', false],
    ["a\nb", false],
]);

it('tells a script from a file that is not one, whatever the case and the line break', function (string $path, bool $script): void {
    expect(CoreIntegrity::isScript($path))->toBe($script);
})->with([
    ['shell.php', true],
    ['SHELL.PHP', true],
    ['a.php5', true],
    ['a.phtml', true],
    ['a.pht', true],
    ['a.phar', true],
    ['a.inc', true],
    ['readme.txt', false],
    ['a.php.jpg', false],
    ["a.txt\n", false],
    ["a.php\n", false],
]);

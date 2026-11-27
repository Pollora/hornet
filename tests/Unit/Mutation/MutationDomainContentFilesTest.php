<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\Scan\ContentKind;
use Pollora\Hornet\Domain\Scan\ContentRules;
use Pollora\Hornet\Domain\Scan\CoreIntegrity;
use Pollora\Hornet\Domain\Scan\ExposureCatalog;
use Pollora\Hornet\Domain\Scan\FileInspector;
use Pollora\Hornet\Domain\Scan\InstalledPlugin;
use Pollora\Hornet\Domain\Scan\PluginIntegrity;
use Pollora\Hornet\Domain\Scan\Suspicion;
use Pollora\Hornet\Tests\Fixtures\ArrayFileSystem;
use Pollora\Hornet\Tests\Fixtures\ArrayTranslator;

/*
 * The rules for the content of the database and for the files, and what the scan says of what it found.
 */

it('holds a javascript: address in the identity of the site as critical, with or without a script', function (): void {
    expect(ContentRules::look('javascript:void(0)', ContentKind::SiteIdentity)['severity'])->toBe(Severity::Critical)
        ->and(ContentRules::look('My site', ContentKind::SiteIdentity)['severity'])->toBeNull();
});

it('reads a javascript: address in a profile only, spaces before it ignored', function (): void {
    expect(ContentRules::look("  \n javascript:alert(1)", ContentKind::Profile)['reasons'])->toBe(['has a javascript: address in a profile'])
        ->and(ContentRules::look('javascript:alert(1)', ContentKind::Post)['reasons'])->toBe([])
        ->and(ContentRules::look('hello', ContentKind::Profile)['reasons'])->toBe([]);
});

it('gives the weak reasons it found', function (): void {
    $found = ContentRules::look('<iframe src="//evil.example" width="0"></iframe>', ContentKind::Post);

    expect($found['reasons'])->toBe(['holds a hidden frame to another site'])
        ->and($found['severity'])->toBe(Severity::Medium);
});

it('names each host once, ten at most', function (): void {
    $text = '<script src="https://a.example.com/x"></script><script src="https://A.example.com/y"></script>';

    foreach (range('b', 'l') as $letter) {
        $text .= "<script src=\"https://{$letter}.example.com/x\"></script>";
    }

    expect(ContentRules::look($text, ContentKind::Post)['hosts'])
        ->toBe(array_map(static fn (string $letter): string => "{$letter}.example.com", range('a', 'j')));
});

it('does not count a folder that only starts like the uploads as the uploads', function (): void {
    $files = new ArrayFileSystem(['uploads-extra/x.php' => '<?php echo 1;']);

    expect((new FileInspector($files, 'uploads'))->inspect('uploads-extra/x.php'))->toBeNull();
});

it('reads the first 64 KB of a server setting file, to the last byte and no further', function (int $end, bool $seen): void {
    $directive = "\nAddType application/x-httpd-php";
    $contents = str_repeat('#', $end - strlen($directive)).$directive;
    $files = new ArrayFileSystem(['.htaccess' => $contents]);

    expect((new FileInspector($files, 'uploads'))->inspect('.htaccess') !== null)->toBe($seen);
})->with([
    'ends on the last byte read' => [65536, true],
    'ends one byte further' => [65537, false],
]);

it('says which kind of setting file runs code', function (string $name, string $reason): void {
    $files = new ArrayFileSystem([$name => 'auto_prepend_file=/tmp/x']);

    expect((new FileInspector($files, 'uploads'))->inspect($name)?->reasons)->toBe([$reason]);
})->with([
    '.htaccess' => ['.htaccess', 'a server setting that runs PHP code or makes a file run as PHP'],
    '.user.ini' => ['.user.ini', 'a setting that runs a PHP file before or after every script'],
]);

it('says each reason of a suspicion again in another language, with the names it holds', function (): void {
    $suspicion = new Suspicion('x.php', Severity::High, [
        'a hidden PHP file',
        'reads like a web shell (score 13): eval(), runs a command (system, exec), decodes hidden data (base64_decode), runs a command (backticks)',
        'runs a command (passthru)',
    ]);
    $translator = new ArrayTranslator([
        'a hidden PHP file' => 'CACHE',
        'reads like a web shell (score %d):' => 'SHELL %d:',
        'eval()' => 'EVAL',
        'runs a command (%s)' => 'LANCE %s',
        'decodes hidden data (%s)' => 'DECODE %s',
        'runs a command (backticks)' => 'ACCENTS',
    ]);
    $say = static fn (?ArrayTranslator $in): string => implode('', array_map(static fn (Message $part): string => $part->render($in), $suspicion->reasonMessages()));

    expect($say($translator))->toBe('CACHE; SHELL 13: EVAL, LANCE system, exec, DECODE base64_decode, ACCENTS; LANCE passthru')
        ->and($say(null))->toBe(implode('; ', $suspicion->reasons));
});

it('refuses a stored suspicion that does not fit', function (mixed $stored): void {
    expect(Suspicion::fromArray($stored))->toBeNull();
})->with([
    'not an array' => ['x'],
    'no path' => [['severity' => 'high', 'reasons' => []]],
    'a number for a severity' => [['path' => 'a', 'severity' => 5, 'reasons' => []]],
    'an unknown severity' => [['path' => 'a', 'severity' => 'huge', 'reasons' => []]],
    'no reasons' => [['path' => 'a', 'severity' => 'high']],
]);

it('keeps the text reasons of a stored suspicion, as a list', function (): void {
    expect(Suspicion::fromArray(['path' => 'a', 'severity' => 'high', 'reasons' => [1, 'x']])?->reasons)->toBe(['x']);
});

it('asks for each file that should never answer', function (): void {
    $paths = array_column(ExposureCatalog::all(), 'path');

    expect($paths)->toContain(
        '.env', '.env.local', '.env.production', '.env.backup',
        'wp-config.php.bak', 'wp-config.php~', 'wp-config.php.old', 'wp-config.php.save', 'wp-config.php.orig', 'wp-config.bak', 'wp-config.txt',
        'backup.sql', 'dump.sql', 'db.sql', 'database.sql', 'site.sql',
        'backup.zip', 'site.zip', 'wordpress.zip',
    );
});

it('looks for unexpected files deep inside wp-includes, and past the files that differ', function (): void {
    $files = new ArrayFileSystem([
        'index.php' => 'changed', 'wp-login.php' => 'changed too', 'wp-includes/a/b/shell.php' => 'x', 'wp-admin/admin.php' => 'ok',
    ]);
    $official = ['index.php' => md5('i'), 'wp-login.php' => md5('l'), 'wp-admin/admin.php' => md5('ok')];

    $verdict = (new CoreIntegrity($files))->inspect($official, [], []);

    expect($verdict?->modified)->toBe(['index.php', 'wp-login.php'])
        ->and($verdict?->unexpected)->toBe(['wp-includes/a/b/shell.php']);
});

it('goes on reading the official list after an entry it leaves out', function (): void {
    $files = new ArrayFileSystem(['index.php' => 'changed']);

    $verdict = (new CoreIntegrity($files))->inspect(['wp-content/x.php' => md5('x'), 'index.php' => md5('i')], [], []);

    expect($verdict?->checked)->toBe(1)->and($verdict?->modified)->toBe(['index.php']);
});

it('refuses a path that leaves the install', function (string $path): void {
    expect(CoreIntegrity::isSafe($path))->toBeFalse();
})->with(['empty' => '', 'absolute' => '/etc/passwd', 'a backslash' => 'a\\b', 'a control character' => "a\nb", 'a parent' => 'a/../b', 'an empty part' => 'a//b']);

it('goes on comparing a plugin after a path it refuses', function (): void {
    $files = new ArrayFileSystem(['p/a.php' => 'changed']);

    $verdict = (new PluginIntegrity($files))->inspect('p', ['../x.php' => [str_repeat('a', 64)], 'a.php' => [hash('sha256', 'original')]]);

    expect($verdict?->checked)->toBe(1)->and($verdict?->modified)->toBe(['a.php']);
});

it('reads the Update URI of a plugin as WordPress does', function (string $uri, bool $elsewhere): void {
    expect((new InstalledPlugin('p', '1.0', 'P', $uri))->updatedElsewhere())->toBe($elsewhere);
})->with([
    'blank' => ['   ', false],
    'the directory in capitals' => ['https://WordPress.org/plugins/p', false],
    'a vendor' => ['https://vendor.example/p', true],
]);

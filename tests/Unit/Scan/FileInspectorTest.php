<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\FileInspector;
use Pollora\Hornet\Domain\Scan\Suspicion;
use Pollora\Hornet\Tests\Fixtures\ArrayFileSystem;

function inspected(string $path, string $contents, string $uploads = 'uploads'): ?Suspicion
{
    return (new FileInspector(new ArrayFileSystem([$path => $contents]), $uploads))->inspect($path);
}

it('says a script in the uploads is high, whatever it holds', function (): void {
    $found = inspected('uploads/2026/11/logo.php', '<?php echo 1;');

    expect($found?->severity)->toBe(Severity::High)->and($found?->reasons)->toBe(['a PHP file in the uploads folder, where nothing should run']);
});

it('lets the silence files of the uploads be', function (string $contents): void {
    expect(inspected('uploads/index.php', $contents))->toBeNull();
})->with([
    ['<?php // Silence is golden.'],
    ["<?php\n// Silence is golden.\n"],
    ["<?php\n/* Silence is golden */\n"],
    ['<?php # nothing'],
    ["<?php\n"],
]);

it('does not let a file pass as silence by hiding code behind the end of a comment', function (): void {
    $found = inspected('uploads/index.php', '<?php // Silence is golden. ?><?php include $_GET["f"];');

    expect($found?->severity)->toBe(Severity::High)->and(implode(';', $found->reasons ?? []))->toContain('includes a file named by request input');
});

it('does not let a file pass as silence when it holds code', function (): void {
    expect(inspected('uploads/index.php', "<?php // Silence is golden.\nsystem(\$_GET['c']);")?->severity)->toBe(Severity::High);
});

it('does not take a script elsewhere in the content for one in the uploads', function (): void {
    expect(inspected('themes/mine/functions.php', '<?php add_action("init", "f");'))->toBeNull()
        ->and(inspected('uploads/a.php', '<?php echo 1;', ''))->toBeNull();
});

it('reports a name with a PHP extension in the middle, and a hidden script', function (): void {
    expect(inspected('uploads/shell.php.jpg', 'x')?->severity)->toBe(Severity::Medium)
        ->and(inspected('uploads/a.PHTML.png', 'x')?->reasons[0])->toContain('PHP extension in the middle')
        ->and(inspected('themes/t/.cache.php', '<?php echo 1;')?->reasons)->toBe(['a hidden PHP file'])
        ->and(inspected('uploads/photo.jpg', 'x'))->toBeNull()
        ->and(inspected('uploads/my.phpfile.jpg', 'x'))->toBeNull();
});

it('reports a web shell wherever it is, high when it runs what a visitor sends', function (): void {
    $high = inspected('themes/t/inc.php', '<?php eval($_POST["x"]);');
    $medium = inspected('mu-plugins/x.php', '<?php eval(base64_decode("x"));');

    expect($high?->severity)->toBe(Severity::High)->and($high?->reasons[0])->toContain('reads like a web shell (score 7)')
        ->and($medium?->severity)->toBe(Severity::Medium);
});

it('reports a server setting that runs code that is not the site\'s', function (string $name, string $contents, ?Severity $inUploads, ?Severity $elsewhere): void {
    expect(inspected('uploads/'.$name, $contents)?->severity)->toBe($inUploads)
        ->and(inspected('themes/t/'.$name, $contents)?->severity)->toBe($elsewhere);
})->with([
    'a handler that makes pictures run' => ['.htaccess', "AddHandler application/x-httpd-php .jpg\n", Severity::High, Severity::Medium],
    'a type' => ['.htaccess', "<FilesMatch \"x\">\nSetHandler application/x-httpd-php\n</FilesMatch>", Severity::High, Severity::Medium],
    'a file run before every script' => ['.user.ini', "auto_prepend_file=/tmp/x.php\n", Severity::High, Severity::Medium],
    'the same through the server' => ['.htaccess', "php_value auto_append_file /tmp/x.php\n", Severity::High, Severity::Medium],
    'an ordinary htaccess' => ['.htaccess', "Options -Indexes\n<Files *.php>\nDeny from all\n</Files>\n", null, null],
    'a comment that says it' => ['.htaccess', "# AddHandler is not used here\n", null, null],
]);

it('does not read a script larger than it reads', function (): void {
    expect(inspected('themes/t/lib.php', '<?php eval($_POST["x"]); '.str_repeat('// padding'.PHP_EOL, 60000)))->toBeNull();
});

it('gives the suspicion as a line and as an array', function (): void {
    $found = inspected('uploads/a.php', '<?php echo 1;');

    expect($found?->line())->toBe('uploads/a.php: a PHP file in the uploads folder, where nothing should run')
        ->and(Suspicion::fromArray($found?->toArray()))->toEqual($found)
        ->and(Suspicion::fromArray(['path' => 'a', 'severity' => 'burning', 'reasons' => []]))->toBeNull()
        ->and(Suspicion::fromArray('text'))->toBeNull()
        ->and(Suspicion::fromArray(['path' => 'a', 'severity' => 'high', 'reasons' => ['x', 4]])?->reasons)->toBe(['x']);
});

<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\Exposure;
use Pollora\Hornet\Domain\Scan\ExposureCatalog;

function exposure(string $path): Exposure
{
    foreach (ExposureCatalog::all() as $one) {
        if ($one->path === $path) {
            return $one;
        }
    }

    throw new LogicException($path);
}

it('recognizes the file by what it holds, and not by a 200', function (string $path, string $body, bool $exposed): void {
    expect(exposure($path)->isExposedBy(200, $body))->toBe($exposed);
})->with([
    ['.env', "APP_KEY=base64:abc\nDB_PASSWORD=secret\n", true],
    ['.env', "# comment\nexport DB_HOST=localhost\n", true],
    ['.env', '<html><body><h1>Page not found</h1><p>The page you want is not here = sorry</p></body></html>', false],
    ['.git/HEAD', "ref: refs/heads/main\n", true],
    ['.git/HEAD', '<html>ref: refs/ nothing</html>', false],
    ['.git/config', "[core]\n\trepositoryformatversion = 0\n", true],
    ['wp-config.php.bak', "<?php\ndefine('DB_PASSWORD', 'x');", true],
    ['wp-config.php.bak', '<html>Not found</html>', false],
    ['backup.sql', "-- MySQL dump\nCREATE TABLE `wp_posts` (", true],
    ['backup.sql', '<html>Welcome to my site</html>', false],
    ['backup.zip', "PK\x03\x04\x14\x00", true],
    ['backup.zip', '<html>PK not at the start</html>', false],
    ['.htpasswd', "admin:\$apr1\$abc\$def\n", true],
    ['.htpasswd', "https://example.com/some/long/address\nhttp://another", false],
    ['phpinfo.php', '<title>phpinfo()</title>PHP Version 8.4', true],
    ['phpinfo.php', '<html>Home</html>', false],
    ['error_log', "[25-Nov-2026 10:00:00 UTC] PHP Warning:  Undefined variable\n", true],
    ['error_log', 'PHP is a language', false],
    ['composer.json', '{"name":"a/b","require":{"php":"^8.3"}}', true],
    ['debug.log', "[25-Nov-2026 10:00:00 UTC] PHP Notice:  x in /var/www/a.php\n", true],
    ['uploads/', '<html><head><title>Index of /wp-content/uploads</title>', true],
    ['uploads/', '<html><head><title>Uploads</title>', false],
    ['plugins/', '<h1>Index of /wp-content/plugins</h1>', true],
]);

it('is not an exposure unless the answer is a 200', function (int $status): void {
    expect(exposure('.env')->isExposedBy($status, "DB_PASSWORD=secret\n"))->toBeFalse();
})->with([301, 302, 401, 403, 404, 500]);

it('puts the files that hold secrets at high, and the ones that only inform lower', function (): void {
    expect(exposure('.env')->severity)->toBe(Severity::High)
        ->and(exposure('.git/config')->severity)->toBe(Severity::High)
        ->and(exposure('backup.sql')->severity)->toBe(Severity::High)
        ->and(exposure('debug.log')->severity)->toBe(Severity::Medium)
        ->and(exposure('uploads/')->severity)->toBe(Severity::Low)
        ->and(exposure('.env')->root)->toBe(Exposure::SITE)
        ->and(exposure('debug.log')->root)->toBe(Exposure::CONTENT);
});

it('has no address twice, and none that leaves the site', function (): void {
    $paths = array_map(static fn (Exposure $one): string => $one->root.':'.$one->path, ExposureCatalog::all());

    expect($paths)->toBe(array_values(array_unique($paths)))
        ->and(array_filter(ExposureCatalog::all(), static fn (Exposure $one): bool => str_contains($one->path, '..') || str_starts_with($one->path, '/') || str_contains($one->path, '://')))->toBe([]);
});

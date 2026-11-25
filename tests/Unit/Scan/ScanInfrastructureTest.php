<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Infrastructure\Config\ScannerSettings;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Pollora\CorePatches;
use Pollora\Hornet\Infrastructure\Scan\NativeFileSystem;
use Pollora\Hornet\Infrastructure\WordPress\WordPressCoreChecksums;
use Pollora\Hornet\Tests\Fixtures\ArraySecrets;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;

/**
 * A folder of files on the real disk, removed when the test ends.
 *
 * @param  array<string, string>  $files
 */
function folder(array $files): string
{
    $root = sys_get_temp_dir().'/hornet-'.bin2hex(random_bytes(6));

    foreach ($files as $path => $contents) {
        $directory = dirname($root.'/'.$path);
        is_dir($directory) || mkdir($directory, 0o777, true);
        file_put_contents($root.'/'.$path, $contents);
    }

    is_dir($root) || mkdir($root, 0o777, true);

    return $root;
}

function remove(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }

    foreach (glob($path.'/{,.}*', GLOB_BRACE) ?: [] as $entry) {
        if (! in_array(basename($entry), ['.', '..'], true)) {
            remove($entry);
        }
    }

    rmdir($path);
}

describe('the disk', function (): void {
    it('lists the files of a folder, in the root or all the way down, with the same paths each time', function (): void {
        $root = folder(['index.php' => 'a', 'wp-admin/index.php' => 'b', 'wp-admin/css/colors.css' => 'c', 'wp-includes/version.php' => 'd']);
        $files = NativeFileSystem::at($root);

        expect($files?->files('', false))->toBe(['index.php'])
            ->and($files?->files('wp-admin', true))->toBe(['wp-admin/css/colors.css', 'wp-admin/index.php'])
            ->and($files?->files('wp-admin', false))->toBe(['wp-admin/index.php'])
            ->and($files?->files('wp-nowhere', true))->toBeNull();

        remove($root);
    });

    it('reads the start of a file and its size, and nothing outside the root', function (): void {
        $root = folder(['a.txt' => 'hello world']);
        $files = NativeFileSystem::at($root);

        expect($files?->read('a.txt', 5))->toBe('hello')
            ->and($files?->read('a.txt', 100))->toBe('hello world')
            ->and($files?->size('a.txt'))->toBe(11)
            ->and($files?->sha256('a.txt'))->toBe(hash('sha256', 'hello world'))
            ->and($files?->read('gone.txt', 5))->toBeNull()
            ->and($files?->size('gone.txt'))->toBeNull()
            ->and($files?->sha256('gone.txt'))->toBeNull()
            ->and($files?->read('../a.txt', 5))->toBeNull()
            ->and($files?->size('../a.txt'))->toBeNull();

        remove($root);
    });

    it('lists the files by bytes, as the cursor of a walk compares them', function (): void {
        $root = folder(['10.php' => 'a', '9.php' => 'b', 'B.php' => 'c', 'a.php' => 'd']);

        expect(NativeFileSystem::at($root)?->files('', false))->toBe(['10.php', '9.php', 'B.php', 'a.php']);

        remove($root);
    });

    it('hashes a file, and says nothing of a file that is not there or not a plain path', function (): void {
        $root = folder(['index.php' => 'index']);
        $files = NativeFileSystem::at($root);

        expect($files?->md5('index.php'))->toBe(md5('index'))
            ->and($files?->md5('gone.php'))->toBeNull()
            ->and($files?->exists('index.php'))->toBeTrue()
            ->and($files?->exists('gone.php'))->toBeFalse()
            // Nothing outside the root is ever read
            ->and($files?->md5('../'.basename($root).'/index.php'))->toBeNull()
            ->and($files?->exists('../'))->toBeFalse()
            ->and($files?->files('../', true))->toBeNull();

        remove($root);
    });

    it('does not walk into a linked folder, and says a file it cannot read exists', function (): void {
        $root = folder(['index.php' => 'index', 'wp-includes/a.php' => 'a']);
        $outside = folder(['secret.php' => 'x']);
        symlink($outside, $root.'/wp-includes/linked');
        file_put_contents($root.'/locked.php', 'x');
        chmod($root.'/locked.php', 0o000);
        $files = NativeFileSystem::at($root);

        expect($files?->files('wp-includes', true))->toBe(['wp-includes/a.php'])
            ->and($files?->exists('locked.php'))->toBeTrue();

        // Root can read anything: the unreadable file is only checked when the tests do not run as root
        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            expect($files?->md5('locked.php'))->toBeNull();
        }

        chmod($root.'/locked.php', 0o644);
        remove($root);
        remove($outside);
    });

    it('follows a link to the WordPress folder (Bedrock, Deployer) and refuses a folder that is not there', function (): void {
        $real = folder(['wp-load.php' => 'load']);
        $link = sys_get_temp_dir().'/hornet-link-'.bin2hex(random_bytes(4));
        symlink($real, $link);

        expect(NativeFileSystem::at($link)?->files('', false))->toBe(['wp-load.php'])
            ->and(NativeFileSystem::at($real.'/nowhere'))->toBeNull();

        unlink($link);
        remove($real);
    });
});

describe('WordPress.org', function (): void {
    it('asks for the version and the locale and nothing else, and reads the list', function (): void {
        $http = new FakeHttpClient;
        $http->answers = [new HttpResponse(200, null, (string) json_encode(['checksums' => ['index.php' => md5('a'), 'wp-includes/version.php' => md5('b'), 'bad' => 12]]))];

        $list = (new WordPressCoreChecksums($http))->checksums('7.1.3', 'fr_FR');

        expect($list)->toBe(['index.php' => md5('a'), 'wp-includes/version.php' => md5('b')])
            ->and($http->gets[0]['url'])->toBe('https://api.wordpress.org/core/checksums/1.0/?version=7.1.3&locale=fr_FR')
            ->and($http->gets[0]['maxBytes'])->toBe(4 * 1024 * 1024);
    });

    it('says there is no list for a version it does not know: 200 with false is not an error', function (): void {
        $http = new FakeHttpClient;
        $http->answers = [new HttpResponse(200, null, '{"checksums":false}')];

        expect((new WordPressCoreChecksums($http))->checksums('9.9.9', 'en_US'))->toBeNull();
    });

    it('is an error when the answer is not the list', function (HttpResponse|TransportException $answer, string $message): void {
        $http = new FakeHttpClient;
        $http->answers = [$answer];

        expect(fn () => (new WordPressCoreChecksums($http))->checksums('7.1.3', 'en_US'))->toThrow(TransportException::class, $message);
    })->with([
        'a server error' => [new HttpResponse(503), 'HTTP 503'],
        'something that is not JSON, or cut short' => [new HttpResponse(200, null, '{"checksums":{"index.php"'), 'not the list'],
        'JSON without the list' => [new HttpResponse(200, null, '{"error":"no"}'), 'not the list'],
        'an empty list' => [new HttpResponse(200, null, '{"checksums":{}}'), 'empty'],
        'a list that is not a list' => [new HttpResponse(200, null, '{"checksums":"yes"}'), 'empty'],
        'nothing answered' => [new TransportException('The request failed (http_request_failed).'), 'http_request_failed'],
    ]);
});

describe('the patches of the project', function (): void {
    it('reads the files a patch changes, dropping the leading folder', function (): void {
        $patch = "diff --git a/wp-includes/l10n.php b/wp-includes/l10n.php\n--- a/wp-includes/l10n.php\n+++ b/wp-includes/l10n.php\t2026-01-01 10:00:00\n@@ -1 +1 @@\n-x\n+y\n"
            ."--- a/wp-admin/index.php\r\n+++ b/wp-admin/index.php\r\n@@ -1 +1 @@\n";

        expect(CorePatches::touched($patch))->toBe(['wp-includes/l10n.php', 'wp-admin/index.php'])
            ->and(CorePatches::touched("+++ wp-includes/l10n.php\n", 0))->toBe(['wp-includes/l10n.php'])
            ->and(CorePatches::touched("+++ a/b/wp-includes/l10n.php\n", 2))->toBe(['wp-includes/l10n.php']);
    });

    it('never allows a path that leaves the install', function (): void {
        expect(CorePatches::touched("+++ b/../../etc/passwd\n+++ b//absolute\n+++ b/\n+++ /dev/null\n"))->toBe([]);
    });

    it('finds the files that the patches of the lock change, taking only a patch whose hash is the one in the lock', function (): void {
        $good = "--- a/wp-includes/l10n.php\n+++ b/wp-includes/l10n.php\n@@ -1 +1 @@\n-x\n+y\n";
        $other = "--- a/wp-admin/index.php\n+++ b/wp-admin/index.php\n@@ -1 +1 @@\n-x\n+y\n";
        $notCore = "--- a/src/App.php\n+++ b/src/App.php\n@@ -1 +1 @@\n-x\n+y\n";
        $project = folder([
            'patches/good.patch' => $good,
            // Same folder, not in the lock: a patch that was swapped is not trusted
            'patches/swapped.patch' => $other,
            'vendor-framework/patches/not-core.patch' => $notCore,
            'patches.lock.json' => (string) json_encode(['patches' => [
                'johnpbloch/wordpress-core' => [['package' => 'johnpbloch/wordpress-core', 'sha256' => hash('sha256', $good), 'depth' => 1], ['sha256' => 'not a hash'], 'text'],
                'some/library' => [['sha256' => hash('sha256', $notCore), 'depth' => 1]],
            ]]),
        ]);

        expect(CorePatches::in($project, [$project.'/patches', $project.'/vendor-framework/patches']))->toBe(['wp-includes/l10n.php']);

        remove($project);
    });

    it('reads the patches of Bedrock\'s core package too, and a patch that is in two folders once', function (): void {
        $patch = "--- a/wp-includes/l10n.php\n+++ b/wp-includes/l10n.php\n@@ -1 +1 @@\n";
        $project = folder(['a/one.patch' => $patch, 'b/two.patch' => $patch, 'patches.lock.json' => (string) json_encode(['patches' => ['roots/wordpress' => [['sha256' => hash('sha256', $patch)]]]])]);

        expect(CorePatches::in($project, [$project.'/a', $project.'/b']))->toBe(['wp-includes/l10n.php']);

        remove($project);
    });

    it('finds nothing without a lock, with a lock that is not JSON, or with no patch file to match', function (): void {
        $none = folder(['a/x.patch' => 'x']);
        $broken = folder(['patches.lock.json' => '{not json']);
        $unmatched = folder(['patches.lock.json' => (string) json_encode(['patches' => ['roots/wordpress' => [['sha256' => str_repeat('a', 64)]]]])]);

        expect(CorePatches::in($none, [$none.'/a']))->toBe([])
            ->and(CorePatches::in($broken, [$broken]))->toBe([])
            ->and(CorePatches::in($unmatched, [$unmatched]))->toBe([]);

        remove($none);
        remove($broken);
        remove($unmatched);
    });

    it('finds nothing in a project that patches nothing', function (): void {
        // The package is installed on its own here: its root has no patches.lock.json
        expect(CorePatches::files())->toBe([]);
    });
});

describe('the settings', function (): void {
    it('has no allowed file until it is told', function (): void {
        expect(Settings::fromArray([], new ArraySecrets)->scanner->allowModified)->toBe([])
            ->and(ScannerSettings::none()->allowModified)->toBe([]);
    });

    it('reads the schedule, and the folders left alone', function (): void {
        $settings = Settings::fromArray(['scanner' => ['schedule' => 'daily', 'ignore_paths' => ['uploads/known/', 'themes/t/vendor']]], new ArraySecrets)->scanner;

        expect($settings->schedule)->toBe('daily')->and($settings->ignorePaths)->toBe(['uploads/known', 'themes/t/vendor'])
            ->and(ScannerSettings::none()->schedule)->toBe('off')->and(ScannerSettings::none()->ignorePaths)->toBe([]);
    });

    it('reads the themes that are the owner\'s, and the probes', function (): void {
        $default = Settings::fromArray([], new ArraySecrets)->scanner;
        $set = Settings::fromArray(['scanner' => ['own_themes' => ['my-theme', 'child_1.0'], 'probes' => false, 'probe_uploads' => true]], new ArraySecrets)->scanner;

        expect($default->ownThemes)->toBe([])->and($default->probes)->toBeTrue()->and($default->probeUploads)->toBeFalse()
            ->and($set->ownThemes)->toBe(['my-theme', 'child_1.0'])->and($set->probes)->toBeFalse()->and($set->probeUploads)->toBeTrue();
    });

    it('reads the files that may differ', function (): void {
        $settings = Settings::fromArray(['scanner' => ['allow_modified' => ['wp-includes/l10n.php']]], new ArraySecrets);

        expect($settings->scanner->allowModified)->toBe(['wp-includes/l10n.php']);
    });

    it('refuses what it does not understand', function (mixed $config, string $message): void {
        expect(fn () => Settings::fromArray(['scanner' => $config], new ArraySecrets))->toThrow(InvalidSettingsException::class, $message);
    })->with([
        'not an array' => ['yes', 'scanner'],
        'a typo' => [['allow_modifed' => []], 'allow_modifed'],
        'not a list' => [['allow_modified' => 'wp-includes/l10n.php'], 'scanner.allow_modified'],
        'a keyed array' => [['allow_modified' => ['a' => 'wp-includes/l10n.php']], 'scanner.allow_modified'],
        'a path that leaves the install' => [['allow_modified' => ['../wp-config.php']], 'scanner.allow_modified'],
        'an absolute path' => [['allow_modified' => ['/var/www/index.php']], 'scanner.allow_modified'],
        'own themes that are not a list' => [['own_themes' => 'my-theme'], 'scanner.own_themes'],
        'an own theme that is a path' => [['own_themes' => ['../x']], 'scanner.own_themes'],
        'an own theme that is not a name' => [['own_themes' => [12]], 'scanner.own_themes'],
        'probes that are not a bool' => [['probes' => 'yes'], 'scanner.probes'],
        'probe_uploads that is not a bool' => [['probe_uploads' => 1], 'scanner.probe_uploads'],
        'a schedule that does not exist' => [['schedule' => 'weekly'], 'scanner.schedule'],
        'a schedule that is not a string' => [['schedule' => true], 'scanner.schedule'],
        'ignored paths that leave the content folder' => [['ignore_paths' => ['../x']], 'scanner.ignore_paths'],
        'ignored paths that are not a list' => [['ignore_paths' => 'uploads'], 'scanner.ignore_paths'],
        'something that is not a path' => [['allow_modified' => [12]], 'scanner.allow_modified'],
    ]);
});

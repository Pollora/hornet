<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\CoreIntegrityTask;
use Pollora\Hornet\Application\Scan\ScanTask;
use Pollora\Hornet\Application\Scan\TaskResult;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Tests\Fixtures\ArrayFileSystem;
use Pollora\Hornet\Tests\Fixtures\FakeCoreChecksums;

function task(ArrayFileSystem $files, FakeCoreChecksums $official, array $allowed = []): ScanTask
{
    return new CoreIntegrityTask($official, $files, '7.1.3', 'en_US', $allowed);
}

function install(): ArrayFileSystem
{
    return new ArrayFileSystem(['index.php' => 'index', 'wp-includes/l10n.php' => 'l10n', 'wp-includes/version.php' => 'version']);
}

it('passes when the core is what WordPress.org published, and asks for this version and locale', function (): void {
    $official = new FakeCoreChecksums(install()->official());

    $result = task(install(), $official)->run(null, false);

    expect($result->finding->outcome)->toBe(Outcome::Pass)
        ->and($result->finding->checkId)->toBe('core-integrity')
        ->and($result->finding->summary)->toContain('The 3 core files match')
        ->and($official->asked)->toBe([['7.1.3', 'en_US']]);
});

it('says it could not verify, not that all is well, when WordPress.org cannot be asked or does not know the version', function (): void {
    $down = task(install(), new FakeCoreChecksums(failure: new TransportException('api.wordpress.org answered HTTP 503.')))->run(null, false);
    $unknown = task(install(), new FakeCoreChecksums(null))->run(null, false);

    expect($down->finding->outcome)->toBe(Outcome::Unknown)->and($down->finding->summary)->toContain('HTTP 503')
        ->and($unknown->finding->outcome)->toBe(Outcome::Unknown)->and($unknown->finding->summary)->toContain('publishes no checksums for WordPress 7.1.3');
});

it('says it could not verify when the files cannot be listed', function (): void {
    $files = install();
    $files->unlistable = ['wp-admin'];

    expect(task($files, new FakeCoreChecksums(install()->official()))->run(null, false)->finding->outcome)->toBe(Outcome::Unknown);
});

it('fails at high when a core file differs or a script that does not belong is there', function (): void {
    $modified = install();
    $modified->files['wp-includes/version.php'] = 'changed';
    $planted = install();
    $planted->files['wp-includes/x.php'] = 'x';

    foreach ([$modified, $planted] as $files) {
        $finding = task($files, new FakeCoreChecksums(install()->official()))->run(null, false)->finding;

        expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->severity)->toBe(Severity::High)->and($finding->fix)->toContain('never changes a file');
    }

    expect(task($modified, new FakeCoreChecksums(install()->official()))->run(null, false)->finding->details)->toBe(['Changed: wp-includes/version.php']);
});

it('fails lower for a missing file and a stray file that is not a script', function (): void {
    $missing = install();
    unset($missing->files['index.php']);
    $stray = install();
    $stray->files['wp-admin/notes.txt'] = 'x';
    $official = new FakeCoreChecksums(install()->official());

    expect(task($missing, $official)->run(null, false)->finding->severity)->toBe(Severity::Medium)
        ->and(task($stray, $official)->run(null, false)->finding->severity)->toBe(Severity::Low);
});

it('lists the first files and counts the rest', function (): void {
    $files = install();

    foreach (range(1, 20) as $number) {
        $files->files['wp-includes/extra'.$number.'.php'] = 'x';
    }

    $finding = task($files, new FakeCoreChecksums(install()->official()))->run(null, false)->finding;

    expect($finding->details)->toHaveCount(16)
        ->and($finding->details[15])->toBe('Not part of WordPress: and 5 more')
        ->and($finding->summary)->toContain('20 core files differ');
});

it('pins an allowed file on the first scan, and keeps passing while it does not change', function (): void {
    $files = install();
    $files->files['wp-includes/l10n.php'] = 'patched';
    $task = task($files, new FakeCoreChecksums(install()->official()), ['wp-includes/l10n.php']);

    $first = $task->run(null, false);
    $second = $task->run($first->pins, false);

    expect($first->finding->outcome)->toBe(Outcome::Pass)
        ->and($first->finding->details)->toBe(['Allowed to differ, and unchanged since first seen: wp-includes/l10n.php'])
        ->and($first->pins)->toEqual(new Pins('7.1.3 (en_US)', ['wp-includes/l10n.php' => md5('patched')]))
        ->and($second->finding->outcome)->toBe(Outcome::Pass);
});

it('fails when an allowed file changes after it was pinned, and keeps the old pin so that it keeps failing', function (): void {
    $files = install();
    $files->files['wp-includes/l10n.php'] = 'patched';
    $official = new FakeCoreChecksums(install()->official());
    $task = task($files, $official, ['wp-includes/l10n.php']);
    $pinned = $task->run(null, false)->pins;

    $files->files['wp-includes/l10n.php'] = 'patched and backdoored';
    $changed = $task->run($pinned, false);
    $again = $task->run($changed->pins, false);

    expect($changed->finding->outcome)->toBe(Outcome::Fail)
        ->and($changed->finding->severity)->toBe(Severity::High)
        ->and($changed->finding->details)->toBe(['Changed since first seen: wp-includes/l10n.php'])
        ->and($changed->pins?->hashes)->toBe(['wp-includes/l10n.php' => md5('patched')])
        ->and($again->finding->outcome)->toBe(Outcome::Fail);
});

it('accepts the allowed files as they are now when asked to pin again', function (): void {
    $files = install();
    $files->files['wp-includes/l10n.php'] = 'patched';
    $task = task($files, new FakeCoreChecksums(install()->official()), ['wp-includes/l10n.php']);
    $pinned = $task->run(null, false)->pins;
    $files->files['wp-includes/l10n.php'] = 'patched again, on purpose';

    $result = $task->run($pinned, true);

    expect($result->finding->outcome)->toBe(Outcome::Pass)
        ->and($result->pins?->hashes)->toBe(['wp-includes/l10n.php' => md5('patched again, on purpose')]);
});

it('pins again by itself when the core is updated: a pin belongs to one release', function (): void {
    $files = install();
    $files->files['wp-includes/l10n.php'] = 'patched for 7.1.3';
    $old = new Pins('7.0.0 (en_US)', ['wp-includes/l10n.php' => md5('patched for 7.0.0')]);

    $result = task($files, new FakeCoreChecksums(install()->official()), ['wp-includes/l10n.php'])->run($old, false);

    expect($result->finding->outcome)->toBe(Outcome::Pass)
        ->and($result->pins?->scope)->toBe('7.1.3 (en_US)');
});

it('keeps the pins when it learned nothing', function (): void {
    $pins = new Pins('7.1.3 (en_US)', ['wp-includes/l10n.php' => md5('patched')]);

    $result = task(install(), new FakeCoreChecksums(failure: new TransportException('down')))->run($pins, false);

    expect($result)->toBeInstanceOf(TaskResult::class)->and($result->pins)->toBe($pins);
});

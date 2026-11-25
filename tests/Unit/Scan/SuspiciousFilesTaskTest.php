<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\SuspiciousFilesTask;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\FileInspector;
use Pollora\Hornet\Domain\Scan\FileSystem;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Tests\Fixtures\ArrayFileSystem;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\TickingFileSystem;

/**
 * @param  list<string>  $ignored
 */
function walk(FileSystem $content, array $ignored = [], ?int $budget = null, ?FakeClock $clock = null): SuspiciousFilesTask
{
    return new SuspiciousFilesTask($content, new FileInspector($content, 'uploads'), $clock ?? new FakeClock, $ignored, $budget);
}

function clean(): ArrayFileSystem
{
    return new ArrayFileSystem(['index.php' => '<?php // Silence is golden.', 'uploads/photo.jpg' => 'x', 'themes/t/functions.php' => '<?php add_action("init", "f");']);
}

it('passes when nothing in the content folder would be left unsaid', function (): void {
    $result = walk(clean())->run(null, false);

    expect($result->finding->outcome)->toBe(Outcome::Pass)->and($result->finding->summary)->toContain('3 files')
        ->and($result->unfinished)->toBeFalse()->and($result->pins)->toBeNull();
});

it('lists what it found, the most serious first, and fails at the worst', function (): void {
    $files = clean();
    $files->files['uploads/b.php.jpg'] = 'x';
    $files->files['uploads/a.php'] = '<?php echo 1;';
    $files->files['themes/t/shell.php'] = '<?php eval($_POST["x"]);';

    $finding = walk($files)->run(null, false)->finding;

    expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->severity)->toBe(Severity::High)
        ->and($finding->summary)->toBe('3 files in the content folder would not be left unsaid.')
        ->and($finding->details[0])->toStartWith('[high] themes/t/shell.php')
        ->and($finding->details[1])->toStartWith('[high] uploads/a.php')
        ->and($finding->details[2])->toStartWith('[medium] uploads/b.php.jpg')
        ->and($finding->fix)->toContain('never changes a file');
});

it('leaves the plugins to the plugin scan, and the caches and tools alone', function (): void {
    $files = clean();

    foreach (['plugins/p/shell.php', 'languages/x.php', 'upgrade/x.php', 'themes/t/node_modules/x/shell.php', 'uploads/cache/x.php', 'uploads/.git/x.php', 'wflogs/x.php'] as $path) {
        $files->files[$path] = '<?php eval($_POST["x"]);';
    }

    expect(walk($files)->run(null, false)->finding->outcome)->toBe(Outcome::Pass);
});

it('leaves alone what the owner says to leave alone: a file, or a folder', function (): void {
    $files = clean();
    $files->files['uploads/known/legacy.php'] = '<?php echo 1;';
    $files->files['themes/t/vendor/shell.php'] = '<?php eval($_POST["x"]);';
    $files->files['uploads/knownnot/a.php'] = '<?php echo 1;';

    $finding = walk($files, ['uploads/known', 'themes/t/vendor/shell.php'])->run(null, false)->finding;

    expect($finding->details)->toHaveCount(1)->and($finding->details[0])->toStartWith('[high] uploads/knownnot/a.php');
});

it('lists the first ones and counts the rest', function (): void {
    $files = clean();

    foreach (range(1, 25) as $number) {
        $files->files['uploads/shell'.$number.'.php'] = '<?php echo 1;';
    }

    $finding = walk($files)->run(null, false)->finding;

    expect($finding->details)->toHaveCount(21)->and($finding->details[20])->toBe('and 5 more');
});

it('says it does not know when the folder cannot be listed, and keeps what it had', function (): void {
    $files = clean();
    $files->unlistable = [''];
    $pins = new Pins('cursor:a', []);

    $result = walk($files)->run($pins, false);

    expect($result->finding->outcome)->toBe(Outcome::Unknown)->and($result->pins)->toBe($pins);
});

describe('a walk that has a time budget', function (): void {
    /** 120 scripts, each read taking one second. */
    function manyScripts(): ArrayFileSystem
    {
        $files = [];

        foreach (range(1, 120) as $number) {
            $files[sprintf('themes/t/f%03d.php', $number)] = '<?php echo '.$number.';';
        }

        $files['themes/t/f030.php'] = '<?php eval($_POST["x"]);';

        return new ArrayFileSystem($files);
    }

    it('stops where it is, keeps the cursor and what it found, and says it is not finished', function (): void {
        $clock = new FakeClock;
        $result = walk(new TickingFileSystem(manyScripts(), $clock, 'PT1S'), budget: 60, clock: $clock)->run(null, false);

        expect($result->unfinished)->toBeTrue()
            ->and($result->finding->outcome)->toBe(Outcome::Unknown)
            ->and($result->finding->summary)->toContain('not finished')
            ->and($result->pins?->scope)->toStartWith('cursor:themes/t/f')
            ->and(array_keys($result->pins?->hashes ?? []))->toBe(['themes/t/f030.php']);
    });

    it('goes on from the cursor, and ends with what both runs found', function (): void {
        $clock = new FakeClock;
        $files = new TickingFileSystem(manyScripts(), $clock, 'PT1S');
        $first = walk($files, budget: 60, clock: $clock)->run(null, false);
        $files2 = manyScripts();
        $files2->files['themes/t/f110.php'] = '<?php system($_GET["c"]);';

        $second = walk($files2)->run($first->pins, false);

        expect($second->unfinished)->toBeFalse()->and($second->pins)->toBeNull()
            ->and($second->finding->outcome)->toBe(Outcome::Fail)
            ->and($second->finding->details)->toHaveCount(2)
            ->and($second->finding->details[0])->toStartWith('[high] themes/t/f030.php');
    });

    it('does not look again at what is before the cursor', function (): void {
        // The only suspect is f030, and the cursor is past it: it was looked at in the first run and is not in the pins
        $result = walk(manyScripts())->run(new Pins('cursor:themes/t/f100.php', []), false);

        expect($result->finding->outcome)->toBe(Outcome::Pass)->and($result->finding->summary)->toContain('20 files');
    });

    it('starts again from the first file when asked to pin again', function (): void {
        $pins = new Pins('cursor:themes/t/f119.php', []);

        $result = walk(manyScripts())->run($pins, true);

        expect($result->finding->details[0])->toContain('themes/t/f030.php');
    });

    it('does not take a pin of another scan for a cursor', function (): void {
        $result = walk(manyScripts())->run(new Pins('7.1.5 (en_US)', ['x' => 'y']), false);

        expect($result->finding->details[0])->toContain('themes/t/f030.php');
    });

    it('does not trust what it kept: a stored suspicion that does not fit is dropped', function (): void {
        $pins = new Pins('cursor:themes/t/f999.php', ['a.php' => 'not json', 'b.php' => '{"path":"b.php","severity":"nope","reasons":[]}']);

        expect(walk(manyScripts())->run($pins, false)->finding->outcome)->toBe(Outcome::Pass);
    });
});

<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\CoreIntegrityTask;
use Pollora\Hornet\Application\Scan\DatabaseScriptsTask;
use Pollora\Hornet\Application\Scan\PluginIntegrityTask;
use Pollora\Hornet\Application\Scan\SuspiciousFilesTask;
use Pollora\Hornet\Application\Scan\TaskResult;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Scan\ContentKind;
use Pollora\Hornet\Domain\Scan\FileInspector;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\PluginIntegrity;
use Pollora\Hornet\Tests\Fixtures\ArrayContentStore;
use Pollora\Hornet\Tests\Fixtures\ArrayFileSystem;
use Pollora\Hornet\Tests\Fixtures\ArrayPluginInventory;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeCoreChecksums;
use Pollora\Hornet\Tests\Fixtures\FakePluginChecksums;
use Pollora\Hornet\Tests\Fixtures\InMemoryBaselineStore;
use Pollora\Hornet\Tests\Fixtures\TickingFileSystem;

// Core files

/**
 * @param  array<string, string|null>  $changes
 * @param  list<string>  $allowed
 */
function mutAppCore(array $changes = [], ?Pins $pinned = null, bool $repin = false, array $allowed = [], array $official = ['index.php' => 'index', 'wp-includes/a.php' => 'a', 'wp-includes/b.php' => 'b']): TaskResult
{
    $list = array_map(md5(...), $official);
    $files = new ArrayFileSystem([...$official, ...$changes]);

    return (new CoreIntegrityTask(new FakeCoreChecksums($list), $files, '7.0', 'fr_FR', $allowed))->run($pinned, $repin);
}

it('pins an allowed file when first seen, and keeps the first pin once it changes', function (): void {
    $first = mutAppCore(['wp-includes/a.php' => 'patched'], allowed: ['wp-includes/a.php']);
    $second = mutAppCore(['wp-includes/a.php' => 'patched again'], $first->pins, allowed: ['wp-includes/a.php']);
    $third = mutAppCore(['wp-includes/a.php' => 'patched again'], $second->pins, allowed: ['wp-includes/a.php']);

    expect($first->pins?->scope)->toBe('7.0 (fr_FR)')
        ->and($first->pins?->hashes)->toBe(['wp-includes/a.php' => md5('patched')])
        ->and($first->finding->outcome)->toBe(Outcome::Pass)
        ->and($first->finding->details)->toBe(['Allowed to differ, and unchanged since first seen: wp-includes/a.php'])
        ->and($second->finding->severity)->toBe(Severity::High)
        ->and($second->finding->details)->toBe(['Changed since first seen: wp-includes/a.php'])
        ->and($second->finding->summary)->toBe('1 core file differs from WordPress 7.0 (fr_FR) on WordPress.org.')
        // The change is still reported at the next scan: the first look stays the reference
        ->and($second->pins?->hashes)->toBe(['wp-includes/a.php' => md5('patched')])
        ->and($third->finding->outcome)->toBe(Outcome::Fail);
});

it('starts pinning over when asked to, or for another release', function (bool $repin, string $scope): void {
    $result = mutAppCore(['wp-includes/a.php' => 'new'], new Pins($scope, ['wp-includes/a.php' => md5('old')]), $repin, ['wp-includes/a.php']);

    expect($result->finding->outcome)->toBe(Outcome::Pass)->and($result->pins?->hashes)->toBe(['wp-includes/a.php' => md5('new')]);
})->with(['asked to' => [true, '7.0 (fr_FR)'], 'another release' => [false, '6.9 (fr_FR)']]);

it('says nothing of allowed files when none differs', function (): void {
    expect(mutAppCore()->finding->details)->toBe([]);
});

it('keeps the pins when it learns nothing', function (): void {
    $pins = new Pins('7.0 (fr_FR)', ['a' => 'b']);
    $down = (new CoreIntegrityTask(new FakeCoreChecksums(failure: new TransportException('x')), new ArrayFileSystem, '7.0', 'fr_FR'))->run($pins, false);
    $none = (new CoreIntegrityTask(new FakeCoreChecksums(null), new ArrayFileSystem, '7.0', 'fr_FR'))->run($pins, false);
    $unlisted = (new CoreIntegrityTask(new FakeCoreChecksums([]), new ArrayFileSystem(unlistable: ['wp-admin']), '7.0', 'fr_FR'))->run($pins, false);

    expect($down->pins)->toBe($pins)->and($none->pins)->toBe($pins)->and($unlisted->pins)->toBe($pins)
        ->and($unlisted->finding->summary)->toBe('The core files cannot be listed from here (open_basedir or permissions).');
});

it('ranks a changed file high, a missing one medium, a stray one low, and counts them all', function (): void {
    $finding = mutAppCore(['wp-includes/a.php' => 'x', 'wp-includes/b.php' => null, 'wp-admin/new.txt' => 't', 'wp-includes/evil.php' => 'e'])->finding;
    $missing = mutAppCore(['wp-admin/new.txt' => 't'], official: ['gone.php' => 'x'])->finding;

    expect($finding->severity)->toBe(Severity::High)
        // An unreadable file is not counted as a difference
        ->and($finding->summary)->toBe('3 core files differ from WordPress 7.0 (fr_FR) on WordPress.org.')
        ->and($finding->details)->toBe(['Changed: wp-includes/a.php', 'Not part of WordPress: wp-admin/new.txt', 'Not part of WordPress: wp-includes/evil.php', 'Could not be read: wp-includes/b.php'])
        ->and($missing->severity)->toBe(Severity::Low);
});

it('lists fifteen files of a kind, and counts what is left', function (int $count): void {
    $changes = [];

    foreach (range(1, $count) as $n) {
        $changes['wp-includes/x'.$n.'.php'] = 'x';
    }

    $finding = mutAppCore($changes)->finding;

    expect($finding->details)->toHaveCount($count)
        ->and(in_array('Not part of WordPress: and 1 more', $finding->details, true))->toBe($count === 16);
})->with([15, 16]);

// Plugins and themes

/**
 * @param  array<string, string>  $plugins  version by slug
 * @param  array<string, array<string, list<string>>|TransportException>  $lists
 * @param  array<string, string>  $files
 */
function mutAppPlugins(array $plugins, array $lists, array $files, InMemoryBaselineStore $baselines, bool $repin = false, int $budget = 240, ?FakeClock $clock = null, string $takes = 'PT0S', string $id = PluginIntegrityTask::ID, string $kind = 'plugin', array $unlistable = ['gone', 'two']): TaskResult
{
    $clock ??= new FakeClock;

    return (new PluginIntegrityTask(new ArrayPluginInventory($plugins), new FakePluginChecksums($lists, $clock, $takes), new PluginIntegrity(new ArrayFileSystem($files, $unlistable)), $baselines, $clock, $budget, $id, $kind))->run(null, $repin);
}

function mutAppSha(string $contents): array
{
    return [hash('sha256', $contents)];
}

it('passes when there is nothing to look at, in the words of what it looks at', function (): void {
    expect(mutAppPlugins([], [], [], new InMemoryBaselineStore)->finding->summary)->toBe('No plugin has a folder of its own.')
        ->and(mutAppPlugins([], [], [], new InMemoryBaselineStore, id: 'theme-integrity', kind: 'theme')->finding->summary)->toBe('No theme has a folder of its own.');
});

it('counts what matches the list apart from what matches the first look', function (): void {
    $baselines = new InMemoryBaselineStore;
    $files = ['listed/a.php' => 'a', 'own/b.php' => 'b'];
    $lists = ['listed/1' => ['a.php' => mutAppSha('a')]];

    $first = mutAppPlugins(['listed' => '1', 'own' => '2'], $lists, $files, $baselines)->finding;
    $second = mutAppPlugins(['listed' => '1', 'own' => '2'], $lists, $files, $baselines)->finding;

    expect($first->summary)->toBe('1 plugin(s) match WordPress.org, 0 match what was first seen, 1 first seen now.')
        ->and($first->details)->toBe(['First look kept for 1 plugin(s) WordPress.org does not know, nothing to compare with yet: own'])
        ->and($second->summary)->toBe('1 plugin(s) match WordPress.org, 1 match what was first seen.')
        ->and($second->details)->toBe([]);
});

it('matches a theme against its first look only, and says so', function (): void {
    $baselines = new InMemoryBaselineStore;
    mutAppPlugins(['t' => '1'], [], ['t/a.php' => 'a'], $baselines, id: 'theme-integrity', kind: 'theme');

    expect(mutAppPlugins(['t' => '1'], [], ['t/a.php' => 'a'], $baselines, id: 'theme-integrity', kind: 'theme')->finding->summary)->toBe('1 theme(s) match what was first seen.');
});

it('does not know when nothing could be compared, and says why', function (): void {
    $finding = mutAppPlugins(['one' => '1', 'two' => '1'], ['one/1' => new TransportException('x')], [], new InMemoryBaselineStore)->finding;
    $first = mutAppPlugins(['own' => '1'], [], ['own/a.php' => 'a'], new InMemoryBaselineStore)->finding;

    expect($finding->outcome)->toBe(Outcome::Unknown)
        ->and($finding->summary)->toBe('Not checked: one (WordPress.org did not answer), two (folder cannot be read)')
        ->and($first->outcome)->toBe(Outcome::Unknown)
        ->and($first->summary)->toStartWith('First look kept for 1 plugin(s)');
});

it('keeps both notes apart, with a space between them', function (): void {
    $finding = mutAppPlugins(['own' => '1', 'gone' => '1'], [], ['own/a.php' => 'a'], new InMemoryBaselineStore)->finding;

    expect($finding->summary)->toBe('First look kept for 1 plugin(s) WordPress.org does not know, nothing to compare with yet: own Not checked: gone (folder cannot be read)');
});

it('does not know about a listed plugin whose folder cannot be read, or a first look that cannot be compared', function (): void {
    $listed = mutAppPlugins(['gone' => '1'], ['gone/1' => ['a.php' => mutAppSha('a')]], [], new InMemoryBaselineStore)->finding;
    $seen = new InMemoryBaselineStore;
    mutAppPlugins(['own' => '1'], [], ['own/a.php' => 'a'], $seen);
    $unreadable = (new PluginIntegrityTask(new ArrayPluginInventory(['own' => '1']), new FakePluginChecksums, new PluginIntegrity(new ArrayFileSystem(unlistable: ['own'])), $seen, new FakeClock))->run(null, false)->finding;

    expect($listed->summary)->toBe('Not checked: gone (folder cannot be read)')
        ->and($unreadable->summary)->toBe('Not checked: own (folder cannot be read)');
});

it('takes a new first look for a new version, and for a plugin that is pinned again', function (string $version, bool $repin): void {
    $baselines = new InMemoryBaselineStore;
    mutAppPlugins(['own' => '1'], [], ['own/a.php' => 'a'], $baselines);

    $finding = mutAppPlugins(['own' => $version], [], ['own/a.php' => 'changed'], $baselines, $repin)->finding;

    expect($finding->outcome)->toBe(Outcome::Unknown)
        ->and($finding->summary)->toStartWith('First look kept')
        ->and($baselines->load()['own']->hashes['a.php'])->toBe(hash('sha256', 'changed'))
        ->and($baselines->load()['own']->version)->toBe($version);
})->with(['a new version' => ['2', false], 'pinned again' => ['1', true]]);

it('forgets what was seen of a plugin that is gone', function (): void {
    $baselines = new InMemoryBaselineStore;
    mutAppPlugins(['own' => '1', 'other' => '1'], [], ['own/a.php' => 'a', 'other/a.php' => 'a'], $baselines);
    mutAppPlugins(['own' => '1'], [], ['own/a.php' => 'a'], $baselines);

    expect(array_keys($baselines->load()))->toBe(['own']);
});

it('fails high for a changed or planted script, medium for a missing file, and names five plugins', function (): void {
    $lists = [];
    $files = [];

    foreach (range(1, 6) as $n) {
        $lists['p'.$n.'/1'] = ['a.php' => mutAppSha('a'), 'gone.php' => mutAppSha('g')];
        $files['p'.$n.'/a.php'] = 'a';
    }

    $missing = mutAppPlugins(array_fill_keys(['p1', 'p2', 'p3', 'p4', 'p5', 'p6'], '1'), $lists, $files, new InMemoryBaselineStore)->finding;
    $planted = mutAppPlugins(['p1' => '1'], $lists, [...$files, 'p1/evil.php' => 'e'], new InMemoryBaselineStore)->finding;
    $changed = mutAppPlugins(['p1' => '1'], ['p1/1' => ['a.php' => mutAppSha('other')]], $files, new InMemoryBaselineStore)->finding;

    expect($missing->severity)->toBe(Severity::Medium)
        ->and($missing->summary)->toBe('6 plugins have changed since it was published or first seen: p1, p2, p3, p4, p5.')
        ->and($missing->details[0])->toBe('Missing: p1/gone.php')
        ->and($missing->fix)->toContain('reinstall the plugin')
        ->and($planted->severity)->toBe(Severity::High)
        ->and($planted->details)->toBe(['Script that does not belong: p1/evil.php', 'Missing: p1/gone.php'])
        ->and($changed->severity)->toBe(Severity::High)
        ->and($changed->details)->toBe(['Changed: p1/a.php'])
        ->and($changed->summary)->toBe('1 plugin has changed since it was published or first seen: p1.');
});

it('puts the notes after the changes, and speaks of themes for themes', function (): void {
    $baselines = new InMemoryBaselineStore;
    mutAppPlugins(['t' => '1'], [], ['t/a.php' => 'a'], $baselines, id: 'theme-integrity', kind: 'theme');

    $finding = mutAppPlugins(['t' => '1', 'gone' => '1'], [], ['t/a.php' => 'changed'], $baselines, id: 'theme-integrity', kind: 'theme')->finding;

    expect($finding->summary)->toBe('1 theme has changed since it was published or first seen: t.')
        ->and($finding->details)->toBe(['Changed: t/a.php', 'Not checked: gone (folder cannot be read)'])
        ->and($finding->fix)->toContain('reinstall the theme');
});

it('lists fifteen changed files of a kind and counts the rest', function (int $count): void {
    $list = [];
    $files = ['p/keep.txt' => 'k'];

    foreach (range(1, $count) as $n) {
        $list['f'.$n.'.php'] = mutAppSha('a');
        $files['p/f'.$n.'.php'] = 'b';
    }

    $finding = mutAppPlugins(['p' => '1'], ['p/1' => $list], $files, new InMemoryBaselineStore)->finding;

    expect($finding->details)->toHaveCount($count)->and(in_array('Changed: and 1 more', $finding->details, true))->toBe($count === 16);
})->with([15, 16]);

it('looks until the deadline is past, and names what it had no time for', function (): void {
    $finding = mutAppPlugins(['a' => '1', 'b' => '1', 'c' => '1'], [], ['a/x.php' => 'x', 'b/x.php' => 'x', 'c/x.php' => 'x'], new InMemoryBaselineStore, budget: 10, takes: 'PT10S')->finding;

    expect($finding->summary)->toEndWith(': a, b Not checked: c (out of time)');
});

it('keeps a first look of 5000 files at most', function (int $count, bool $kept): void {
    $files = [];

    foreach (range(1, $count) as $n) {
        $files['big/f'.$n.'.txt'] = 'x';
    }

    $baselines = new InMemoryBaselineStore;
    $finding = mutAppPlugins(['big' => '1'], [], $files, $baselines)->finding;

    expect($baselines->load() !== [])->toBe($kept)->and(str_contains($finding->summary, 'too many files'))->toBe(! $kept);
})->with([[5000, true], [5001, false]]);

// Suspicious files

/**
 * @param  list<string>  $ignored
 */
function mutAppWalk(ArrayFileSystem $files, ?Pins $pinned = null, bool $repin = false, array $ignored = [], ?int $budget = null, string $takes = 'PT0S'): TaskResult
{
    $clock = new FakeClock;
    $content = new TickingFileSystem($files, $clock, $takes);

    return (new SuspiciousFilesTask($content, new FileInspector($content, 'uploads'), $clock, $ignored, $budget))->run($pinned, $repin);
}

/**
 * @return array<string, string> uploaded scripts u/001.php...
 */
function mutAppUploads(int $count): array
{
    $files = [];

    foreach (range(1, $count) as $n) {
        $files[sprintf('uploads/%04d.php', $n)] = '<?php echo 1;';
    }

    return $files;
}

it('stops at the deadline between two fifties, and goes on from there to the same result', function (): void {
    $files = new ArrayFileSystem(mutAppUploads(120));

    $first = mutAppWalk($files, budget: 1, takes: 'PT1S');
    $rest = mutAppWalk($files, $first->pins);

    expect($first->unfinished)->toBeTrue()
        ->and($first->finding->outcome)->toBe(Outcome::Unknown)
        ->and($first->finding->summary)->toBe('The walk of the content folder is not finished: 50 files looked at in this run, it goes on from uploads/0050.php next time.')
        ->and($first->pins?->scope)->toBe('cursor:uploads/0050.php')
        ->and($first->pins?->hashes)->toHaveCount(50)
        ->and($rest->unfinished)->toBeFalse()
        ->and($rest->pins)->toBeNull()
        ->and($rest->finding->summary)->toBe('120 files in the content folder would not be left unsaid.');
});

it('starts over when asked to, whatever an earlier run kept', function (): void {
    $files = new ArrayFileSystem(mutAppUploads(60));
    $first = mutAppWalk($files, budget: 1, takes: 'PT1S');
    $files->files = ['uploads/photo.jpg' => 'x'];

    $again = mutAppWalk($files, $first->pins, true);

    expect($again->finding->outcome)->toBe(Outcome::Pass)->and($again->finding->summary)->toBe('Nothing suspicious in the 1 files of the content folder that were looked at.');
});

it('keeps two hundred findings between runs and counts those it cannot keep', function (): void {
    $files = new ArrayFileSystem(mutAppUploads(300));

    $first = mutAppWalk($files, budget: 245, takes: 'PT1S');
    $rest = mutAppWalk($files, $first->pins);

    expect($first->pins?->scope)->toBe('cursor:uploads/0250.php')
        ->and($first->pins?->hashes)->toHaveCount(201)
        ->and($first->pins?->hashes['~dropped'])->toBe('50')
        ->and($rest->finding->summary)->toBe('300 files in the content folder would not be left unsaid.')
        ->and($rest->finding->details)->toHaveCount(22)
        ->and($rest->finding->details[20])->toBe('and 230 more')
        ->and($rest->finding->details[21])->toStartWith('and 50 more were found in an earlier run');
});

it('still fails when only the count of what was dropped is left', function (): void {
    $finding = mutAppWalk(new ArrayFileSystem(['z.txt' => 'x']), new Pins('cursor:a', ['~dropped' => '3', 'b' => 'not json']))->finding;

    expect($finding->outcome)->toBe(Outcome::Fail)->and($finding->severity)->toBe(Severity::Medium)
        ->and($finding->summary)->toBe('3 files in the content folder would not be left unsaid.');
});

it('does not take pins that are not a cursor for one', function (): void {
    $finding = mutAppWalk(new ArrayFileSystem(mutAppUploads(1)), new Pins('zzzzzzzzzzzzzzzzzzzz', []))->finding;

    expect($finding->outcome)->toBe(Outcome::Fail);
});

it('keeps the pins when the content folder cannot be listed', function (): void {
    $pins = new Pins('cursor:a');
    $result = mutAppWalk(new ArrayFileSystem(unlistable: ['']), $pins);

    expect($result->finding->outcome)->toBe(Outcome::Unknown)->and($result->pins)->toBe($pins);
});

it('lists twenty files and counts the rest', function (int $count): void {
    $finding = mutAppWalk(new ArrayFileSystem(mutAppUploads($count)))->finding;

    expect($finding->details)->toHaveCount($count)->and(in_array('and 1 more', $finding->details, true))->toBe($count === 21)
        ->and($finding->details[0])->toStartWith('[high] uploads/0001.php: ');
})->with([20, 21]);

it('leaves alone an ignored file or folder, and nothing that only starts like it', function (string $ignored, int $found): void {
    $files = new ArrayFileSystem(['uploads/a/x.php' => '<?php echo 1;', 'uploads/ab.php' => '<?php echo 1;']);

    $result = mutAppWalk($files, ignored: [$ignored]);

    expect($result->finding->outcome)->toBe($found === 0 ? Outcome::Pass : Outcome::Fail)
        ->and($result->finding->details)->toHaveCount($found);
})->with(['a folder' => ['uploads/a', 1], 'with a slash' => ['uploads/a/', 1], 'a file' => ['uploads/ab.php', 1], 'the parent' => ['uploads', 0], 'a part of a name' => ['uploads/ab', 2]]);

// Scripts in the database

const MUTAPP_EVIL = '<script>eval(atob("eA=="));</script>';

/**
 * @param  list<string>  $ignored
 */
function mutAppDb(ArrayContentStore $store, ?Pins $pinned = null, bool $repin = false, array $ignored = [], ?int $budget = null, ?FakeClock $clock = null, float $took = 0.1): TaskResult
{
    $calls = 0;

    return (new DatabaseScriptsTask($store, $clock ?? new FakeClock, $ignored, $budget, static function () use (&$calls, $took): float {
        // Each range is timed by two calls: the second is $took after the first
        return $calls++ % 2 === 0 ? 0.0 : $took;
    }))->run($pinned, $repin);
}

it('reads ranges whose width follows the time a range takes', function (float $took, array $expected): void {
    // One entry far from the next: each range starts at an entry, and its width is what is observed
    $store = new ArrayContentStore(['posts' => array_fill_keys([1, 10001, 20001, 30001, 40001, 50001], 'x')]);

    mutAppDb($store, took: $took);

    expect(array_map(static fn (array $range): int => $range[2] - $range[1] + 1, array_slice($store->ranges, 0, count($expected))))->toBe($expected);
})->with([
    'fast: doubles, up to five thousand' => [0.01, [500, 1000, 2000, 4000, 5000, 5000]],
    'slow: halves, down to fifty' => [1.0, [500, 250, 125, 62, 50, 50]],
    'in between: stays' => [0.1, [500, 500, 500]],
    'exactly fifty milliseconds is not fast' => [0.05, [500, 500]],
    'exactly a quarter second is not slow' => [0.25, [500, 500]],
]);

it('reads one table after another from its start, without a gap', function (): void {
    $store = new ArrayContentStore(['posts' => [7 => 'x', 900 => 'x'], 'comments' => [3 => 'x']]);

    mutAppDb($store);

    expect($store->ranges)->toBe([['posts', 7, 506], ['posts', 900, 1399], ['comments', 3, 502]]);
});

it('reads twenty entries at a time', function (): void {
    $store = new ArrayContentStore(['posts' => array_fill_keys(range(1, 41), MUTAPP_EVIL)]);

    mutAppDb($store);

    expect($store->batches)->toBe([20, 20, 1]);
});

it('counts what it looked at, the hosts of scripts, and no more than ten of them', function (): void {
    $rows = [];

    foreach (range(1, 12) as $n) {
        $rows[$n] = '<script src="https://cdn'.$n.'.example.com/a.js"></script>';
    }

    $rows[13] = $rows[1];
    $finding = mutAppDb(new ArrayContentStore(['posts' => $rows]))->finding;

    expect($finding->outcome)->toBe(Outcome::Pass)
        ->and($finding->summary)->toBe('No disguised script in the database (13 entries holding code were looked at).')
        ->and($finding->details)->toBe(['Scripts are loaded from: '.implode(', ', array_map(static fn (int $n): string => 'cdn'.$n.'.example.com', range(1, 10))).' (not judged).']);
});

it('says nothing of hosts when there are none', function (): void {
    expect(mutAppDb(new ArrayContentStore(['posts' => []]))->finding->details)->toBe([]);
});

it('names each kind of entry, the worst first, and never an option name that is not plain', function (): void {
    $finding = mutAppDb(new ArrayContentStore([
        'posts' => [4 => MUTAPP_EVIL],
        'comments' => [5 => [ContentKind::Comment, '', '<script>x</script>']],
        'users' => [6 => [ContentKind::Profile, '', 'javascript:alert(1)']],
        'options' => [
            7 => [ContentKind::Option, 'widget_text', MUTAPP_EVIL],
            8 => [ContentKind::Option, 'bad name <b>', MUTAPP_EVIL],
            9 => [ContentKind::SiteIdentity, 'blogname', '<script>x</script>'],
        ],
    ]))->finding;

    expect($finding->severity)->toBe(Severity::Critical)
        ->and($finding->details[0])->toStartWith('[critical] option blogname: ')
        ->and(array_map(static fn (string $line): string => explode(': ', $line)[0], $finding->details))->toBe(['[critical] option blogname', '[high] post #4', '[high] comment #5', '[high] user #6', '[high] option widget_text', '[high] option #8'])
        ->and($finding->details[1])->toBe('[high] post #4: runs code that is decoded first')
        ->and($finding->summary)->toBe('6 entries of the database hold a script that looks disguised.')
        ->and(json_encode($finding->details))->not->toContain('bad name');
});

it('separates the reasons of one entry', function (): void {
    $finding = mutAppDb(new ArrayContentStore(['posts' => [1 => '<script>eval(atob("x")); document.write(atob("y"));</script>']]))->finding;

    expect($finding->details[0])->toBe('[high] post #1: runs code that is decoded first; writes decoded code into the page');
});

it('leaves alone the entries the owner listed, by their kind', function (string $ignored, ContentKind $kind): void {
    $finding = mutAppDb(new ArrayContentStore(['t' => [3 => [$kind, 'my_option', MUTAPP_EVIL]]]), ignored: [$ignored])->finding;

    expect($finding->outcome)->toBe(Outcome::Pass)->and($finding->summary)->toContain('(0 entries');
})->with([
    ['post:3', ContentKind::Post],
    ['comment:3', ContentKind::Comment],
    ['user:3', ContentKind::Profile],
    ['option:my_option', ContentKind::Option],
    ['option:my_option', ContentKind::SiteIdentity],
]);

it('keeps two hundred entries and counts the others, listing twenty', function (): void {
    $finding = mutAppDb(new ArrayContentStore(['posts' => array_fill_keys(range(1, 205), MUTAPP_EVIL)]))->finding;

    expect($finding->summary)->toStartWith('205 entries')
        ->and($finding->details)->toHaveCount(21)
        ->and($finding->details[20])->toBe('and 185 more');
});

it('does not count a rest when exactly twenty are listed', function (): void {
    expect(mutAppDb(new ArrayContentStore(['posts' => array_fill_keys(range(1, 20), MUTAPP_EVIL)]))->finding->details)->toHaveCount(20);
});

it('stops between two ranges once past the deadline, and goes on with everything it had', function (): void {
    $clock = new FakeClock;
    $rows = ['posts' => [1 => MUTAPP_EVIL, 2 => '<script src="https://cdn.example.com/a.js"></script>', 600 => MUTAPP_EVIL], 'comments' => [1 => 'x']];
    $store = new ArrayContentStore($rows, static fn () => $clock->advance('PT10S'));

    $first = mutAppDb($store, budget: 5, clock: $clock);
    $rest = mutAppDb(new ArrayContentStore($rows), $first->pins);

    expect($store->ranges)->toBe([['posts', 1, 500]])
        ->and($first->unfinished)->toBeTrue()
        ->and($first->pins?->scope)->toBe('cursor:posts:500')
        ->and($first->finding->outcome)->toBe(Outcome::Fail)
        ->and($first->finding->details)->toContain('The database is not read through yet: it goes on next time.')
        ->and($rest->unfinished)->toBeFalse()
        ->and($rest->finding->summary)->toStartWith('2 entries')
        ->and($rest->finding->details)->toHaveCount(2);
});

it('does not stop at the deadline itself', function (): void {
    $clock = new FakeClock;
    $store = new ArrayContentStore(['posts' => [1 => 'x', 600 => 'x']], static fn () => $clock->advance('PT5S'));

    expect(mutAppDb($store, budget: 5, clock: $clock)->unfinished)->toBeFalse();
});

it('does not know yet when it stopped without finding anything, and keeps its counts and hosts', function (): void {
    $clock = new FakeClock;
    $rows = ['posts' => [1 => '<script src="https://cdn.example.com/a.js"></script>', 600 => 'x']];

    $first = mutAppDb(new ArrayContentStore($rows, static fn () => $clock->advance('PT10S')), budget: 5, clock: $clock);
    $rest = mutAppDb(new ArrayContentStore($rows), $first->pins);

    expect($first->finding->outcome)->toBe(Outcome::Unknown)
        ->and($first->finding->summary)->toBe('The database is not read through yet: it goes on next time.')
        ->and($rest->finding->summary)->toContain('(1 entries')
        ->and($rest->finding->details)->toBe(['Scripts are loaded from: cdn.example.com (not judged).']);
});

it('keeps what it found and its place when the database cannot be read', function (): void {
    $store = new ArrayContentStore(['posts' => [1 => MUTAPP_EVIL], 'comments' => [1 => 'x']]);
    $store->broken = 'comments';
    $nothing = new ArrayContentStore(['posts' => [1 => 'x']]);
    $nothing->broken = 'posts';

    $found = mutAppDb($store);
    $none = mutAppDb($nothing);

    expect($found->finding->outcome)->toBe(Outcome::Fail)
        ->and($found->finding->details)->toContain('The database could not be read: nothing can be said of the part that was not.')
        ->and($found->pins?->scope)->toBe('cursor:comments:0')
        ->and($found->unfinished)->toBeFalse()
        ->and($none->finding->outcome)->toBe(Outcome::Unknown)
        ->and($none->pins?->scope)->toBe('cursor:posts:0');
});

it('starts over when asked, or when the place it kept is not one', function (Pins $pins, bool $repin): void {
    $store = new ArrayContentStore(['posts' => [1 => MUTAPP_EVIL], 'comments' => []]);

    expect(mutAppDb($store, $pins, $repin)->finding->outcome)->toBe(Outcome::Fail)->and($store->ranges[0])->toBe(['posts', 1, 500]);
})->with([
    'asked' => [new Pins('cursor:comments:0'), true],
    'an unknown table' => [new Pins('cursor:nope:900'), false],
    'something before' => [new Pins('xcursor:comments:0'), false],
    'something after' => [new Pins("cursor:comments:0\n"), false],
]);

it('reads only what is sound of what it kept', function (): void {
    $pins = new Pins('cursor:posts:0', [
        '~hosts' => '["a.example.com", 5]',
        '~counts' => '["x", "y"]',
        'post:1' => 'not json',
        'post:2' => '{"severity":"nope","reasons":[]}',
        'post:3' => '{"severity":"high","reasons":"x"}',
        'post:4' => '{"severity":"medium","name":5,"reasons":["holds a hidden frame to another site", 7]}',
    ]);

    $finding = mutAppDb(new ArrayContentStore(['posts' => []]), $pins)->finding;

    expect($finding->severity)->toBe(Severity::Medium)
        ->and($finding->summary)->toStartWith('1 entry')
        ->and($finding->details)->toBe(['[medium] post #4: holds a hidden frame to another site']);
});

it('adds to the counts of before', function (): void {
    $pins = new Pins('cursor:posts:0', ['~counts' => '[3, 2]', '~hosts' => '["a.example.com"]']);

    $finding = mutAppDb(new ArrayContentStore(['posts' => [1 => '<script></script>']]), $pins)->finding;
    $dropped = mutAppDb(new ArrayContentStore(['posts' => [1 => MUTAPP_EVIL]]), $pins)->finding;

    expect($finding->summary)->toContain('(4 entries')
        ->and($finding->details)->toBe(['Scripts are loaded from: a.example.com (not judged).'])
        ->and($dropped->summary)->toStartWith('3 entries');
});

/**
 * A clock that has moved on each time it is read: with a budget of nothing, the time is over from the first look.
 */
function mutAppRunningClock(): Clock
{
    return new class implements Clock
    {
        private int $reads = 0;

        public function now(): DateTimeImmutable
        {
            return (new DateTimeImmutable('2026-11-25 10:00:00 UTC'))->modify('+'.$this->reads++.' seconds');
        }
    };
}

function mutAppHurriedWalk(ArrayFileSystem $files, ?Pins $pinned = null): TaskResult
{
    return (new SuspiciousFilesTask($files, new FileInspector($files, 'uploads'), mutAppRunningClock(), [], 0))->run($pinned, false);
}

it('looks at one range even when its time is already over', function (): void {
    $store = new ArrayContentStore(['posts' => [1 => 'x', 600 => 'x']]);

    $result = (new DatabaseScriptsTask($store, mutAppRunningClock(), [], 0))->run(null, false);

    expect($store->ranges)->toBe([['posts', 1, 500]])->and($result->unfinished)->toBeTrue();
});

it('names the host of a script once', function (): void {
    $script = '<script src="https://cdn.example.com/a.js"></script>';

    expect(mutAppDb(new ArrayContentStore(['posts' => [1 => $script, 2 => $script]]))->finding->details)->toBe(['Scripts are loaded from: cdn.example.com (not judged).']);
});

it('keeps two hundred entries between runs, and remembers how many more there were', function (): void {
    $clock = new FakeClock;
    $keys = range(1, 205);
    $rows['posts'] = array_replace(array_fill_keys($keys, '<script>eval(atob("x"))</script>'), [900 => 'x']);
    $store = new ArrayContentStore($rows, static fn () => $clock->advance('PT10S'));

    $first = mutAppDb($store, budget: 5, clock: $clock);
    $rest = mutAppDb(new ArrayContentStore($rows), $first->pins);

    expect($first->pins?->hashes)->toHaveCount(202)
        ->and($rest->finding->summary)->toStartWith('205 entries');
});

it('starts its counts at nothing when the place it kept has none', function (array $kept, string $entries, string $total): void {
    $clean = mutAppDb(new ArrayContentStore(['posts' => []]), new Pins('cursor:posts:0', $kept))->finding;
    $found = mutAppDb(new ArrayContentStore(['posts' => [1 => '<script>eval(atob("x"))</script>']]), new Pins('cursor:posts:0', $kept))->finding;

    expect($clean->summary)->toContain('('.$entries.' entries')->and($found->summary)->toStartWith($total);
})->with([
    'no counts' => [[], '0', '1 entry'],
    'only the first is a number' => [['~counts' => '[3, "x"]'], '3', '1 entry'],
    'only the second is a number' => [['~counts' => '["x", 2]'], '0', '3 entries'],
]);

it('keeps only the hosts and reasons that are words, and the name of an option', function (): void {
    $hosts = mutAppDb(new ArrayContentStore(['posts' => []]), new Pins('cursor:posts:0', ['~hosts' => '[5, "a.example.com"]']))->finding;
    $found = mutAppDb(new ArrayContentStore(['posts' => []]), new Pins('cursor:posts:0', ['option:4' => '{"severity":"high","name":"widget_text","reasons":[7,"has a script in a comment"]}']))->finding;

    expect($hosts->details)->toBe(['Scripts are loaded from: a.example.com (not judged).'])
        ->and($found->details)->toBe(['[high] option widget_text: has a script in a comment']);
});

it('counts only what it found when the database stops answering', function (): void {
    $store = new ArrayContentStore(['posts' => [1 => [ContentKind::Post, '', '<script>eval(atob("x"))</script>']], 'comments' => [1 => 'x']]);
    $store->broken = 'comments';

    expect(mutAppDb($store)->finding->summary)->toBe('1 entry of the database holds a script that looks disguised.');
});

it('does not stop at the deadline itself, nor before fifty files', function (): void {
    $files = new ArrayFileSystem(mutAppUploads(60));

    expect(mutAppWalk($files, budget: 50, takes: 'PT1S')->unfinished)->toBeFalse()
        ->and(mutAppHurriedWalk($files)->pins?->scope)->toBe('cursor:uploads/0050.php');
});

it('adds what it could not keep to what an earlier run could not keep', function (): void {
    $earlier = new Pins('cursor:uploads/0000', ['~dropped' => '4']);
    $files = new ArrayFileSystem(mutAppUploads(60));

    $partial = mutAppHurriedWalk($files, $earlier);

    expect($partial->pins?->hashes['~dropped'])->toBe('4');
});

it('says when exactly one could not be kept', function (): void {
    $files = new ArrayFileSystem(mutAppUploads(300));
    $first = mutAppWalk($files, budget: 195, takes: 'PT1S');
    // 200 files looked at: all are kept. One more kept from before makes 201
    $one = mutAppWalk($files, new Pins('cursor:uploads/0000', ['a.php' => '{"path":"a.php","severity":"low","reasons":[]}', ...$first->pins->hashes]), budget: 195, takes: 'PT1S');

    expect($first->pins?->hashes)->toHaveCount(200)
        ->and($one->pins?->hashes['~dropped'])->toBe('1');
});

it('reads on after the count of the dropped, and never takes it below nothing', function (): void {
    $kept = new Pins('cursor:a', ['~dropped' => '-5', 'b.php' => '{"path":"b.php","severity":"low","reasons":["is odd"]}']);

    $finding = mutAppWalk(new ArrayFileSystem(['z.txt' => 'x']), $kept)->finding;

    expect($finding->summary)->toBe('1 file in the content folder would not be left unsaid.')
        ->and($finding->details)->toBe(['[low] b.php: is odd'])
        ->and(mutAppWalk(new ArrayFileSystem(['z.txt' => 'x']), new Pins('cursor:a', ['~dropped' => '-5']))->finding->summary)->toStartWith('Nothing suspicious');
});

it('says one dropped file in the singular, and that one was not kept', function (): void {
    $finding = mutAppWalk(new ArrayFileSystem(['z.txt' => 'x']), new Pins('cursor:a', ['~dropped' => '1']))->finding;

    expect($finding->summary)->toBe('1 file in the content folder would not be left unsaid.')
        ->and($finding->details)->toHaveCount(1)
        ->and($finding->details[0])->toStartWith('and 1 more were found in an earlier run');
});

it('sorts the files by severity before their path, and tells why each is there', function (): void {
    $finding = mutAppWalk(new ArrayFileSystem(['uploads/a.php.jpg' => 'x', 'uploads/b.php' => '<?php echo 1;', 'uploads/c.php' => '<?php echo 1;']))->finding;

    expect($finding->severity)->toBe(Severity::High)
        ->and($finding->details[0])->toMatch('#^\[high\] uploads/b\.php: \S.+$#')
        ->and($finding->details[1])->toStartWith('[high] uploads/c.php: ')
        ->and($finding->details[2])->toStartWith('[medium] uploads/a.php.jpg: ');
});

it('counts and lists a missing core file, and keeps the unreadable one in a pass', function (): void {
    $both = mutAppCore(['wp-includes/a.php' => 'x'], official: ['wp-includes/a.php' => 'a', 'gone.php' => 'g'])->finding;
    $missing = mutAppCore(official: ['index.php' => 'i'], changes: ['index.php' => null])->finding;

    expect($both->details)->toContain('Changed: wp-includes/a.php')
        ->and($missing->outcome)->toBe(Outcome::Pass)
        ->and($missing->details)->toBe(['Could not be read: index.php']);
});

it('names every plugin it had no time for', function (): void {
    $finding = mutAppPlugins(['a' => '1', 'b' => '1', 'c' => '1'], [], ['a/x.php' => 'x'], new InMemoryBaselineStore, budget: 5, takes: 'PT10S')->finding;

    expect($finding->summary)->toEndWith('Not checked: b (out of time), c (out of time)');
});

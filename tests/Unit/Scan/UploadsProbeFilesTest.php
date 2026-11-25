<?php

declare(strict_types=1);

use Pollora\Hornet\Infrastructure\Scan\UploadsProbeFiles;

function uploadsFolder(): string
{
    $folder = sys_get_temp_dir().'/hornet-up-'.bin2hex(random_bytes(6));
    mkdir($folder, 0o777, true);

    return $folder;
}

function emptyFolder(string $folder): void
{
    foreach (glob($folder.'/{,.}*', GLOB_BRACE) ?: [] as $entry) {
        is_file($entry) && unlink($entry);
    }

    rmdir($folder);
}

it('writes its file, and removes it', function (): void {
    $folder = uploadsFolder();
    $files = new UploadsProbeFiles($folder);

    expect($files->plant('hornet-probe-0123456789abcdef.php', "<?php echo strrev('x');"))->toBeTrue()
        ->and(file_get_contents($folder.'/hornet-probe-0123456789abcdef.php'))->toBe("<?php echo strrev('x');");

    $files->remove('hornet-probe-0123456789abcdef.php');

    expect(file_exists($folder.'/hornet-probe-0123456789abcdef.php'))->toBeFalse();

    emptyFolder($folder);
});

it('writes and removes only a name that is its own', function (string $name): void {
    $folder = uploadsFolder();
    file_put_contents($folder.'/'.basename($name), 'precious');
    $files = new UploadsProbeFiles($folder);

    expect($files->plant($name, 'x'))->toBeFalse();

    $files->remove($name);

    expect(file_get_contents($folder.'/'.basename($name)))->toBe('precious');

    emptyFolder($folder);
})->with([
    'index.php',
    '../hornet-probe-0123456789abcdef.php',
    'hornet-probe-XYZ.php',
    'hornet-probe-0123456789abcdef.php.jpg',
    "hornet-probe-0123456789abcdef.php\n",
]);

it('does not write where it cannot', function (): void {
    expect((new UploadsProbeFiles('/nowhere/at/all'))->plant('hornet-probe-0123456789abcdef.php', 'x'))->toBeFalse();
});

it('sweeps what a run that died left, and nothing else', function (): void {
    $folder = uploadsFolder();
    file_put_contents($folder.'/hornet-probe-aaaaaaaaaaaaaaaa.php', "<?php echo strrev('abc');\n");
    // Named like a probe, but it is not ours: it is left
    file_put_contents($folder.'/hornet-probe-bbbbbbbbbbbbbbbb.php', '<?php // somebody else');
    file_put_contents($folder.'/photo.php', "<?php echo strrev('abc');\n");

    (new UploadsProbeFiles($folder))->plant('hornet-probe-cccccccccccccccc.php', 'x');

    expect(file_exists($folder.'/hornet-probe-aaaaaaaaaaaaaaaa.php'))->toBeFalse()
        ->and(file_exists($folder.'/hornet-probe-bbbbbbbbbbbbbbbb.php'))->toBeTrue()
        ->and(file_exists($folder.'/photo.php'))->toBeTrue()
        ->and(file_exists($folder.'/hornet-probe-cccccccccccccccc.php'))->toBeTrue();

    emptyFolder($folder);
});

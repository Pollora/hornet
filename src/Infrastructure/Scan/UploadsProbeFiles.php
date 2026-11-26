<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Scan;

use Pollora\Hornet\Domain\Scan\ProbeFiles;

/**
 * The uploads folder, for the one file that the scan puts there and removes. It writes only a name that is its own
 * (`hornet-probe-` and 16 hexadecimal characters, `.php`), and removes only what holds its own mark.
 *
 * @internal
 */
final readonly class UploadsProbeFiles implements ProbeFiles
{
    private const string NAME = '/^hornet-probe-[0-9a-f]{16}\.php$/D';

    /** What is in the file, so that nothing else is ever swept. */
    private const string MARK = "<?php echo strrev('";

    public function __construct(private string $folder) {}

    public function plant(string $name, string $contents): bool
    {
        if (preg_match(self::NAME, $name) !== 1 || ! is_dir($this->folder) || ! is_writable($this->folder)) {
            return false;
        }

        $this->sweep();

        return file_put_contents($this->folder.'/'.$name, $contents) !== false;
    }

    public function remove(string $name): void
    {
        $path = $this->folder.'/'.$name;

        if (preg_match(self::NAME, $name) === 1 && is_file($path)) {
            unlink($path);
        }
    }

    /**
     * What an earlier run left, because it died between the file and its removal.
     */
    private function sweep(): void
    {
        foreach (glob($this->folder.'/hornet-probe-*.php') ?: [] as $path) {
            if (preg_match(self::NAME, basename($path)) === 1 && str_starts_with((string) file_get_contents($path, false, null, 0, 64), self::MARK)) {
                unlink($path);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Scan;

use Composer\Autoload\ClassLoader;
use Pollora\Hornet\Domain\Scan\ComposerInventory;
use Pollora\Hornet\Domain\Scan\ComposerPackage;
use Pollora\Hornet\Domain\Scan\ComposerPackages;

/**
 * The packages of the project that installed Hornet, from `vendor/composer/installed.json` (what is on the disk), or else from
 * `composer.lock` (without its development packages: the file does not say whether they were installed).
 *
 * "Comes from Packagist" is read from `notification-url`, which only packagist.org sets to its own address. The address of the
 * archive proves nothing: a private GitHub repository is downloaded from api.github.com like any public one.
 *
 * @internal
 */
final readonly class InstalledComposerPackages implements ComposerPackages
{
    private const string PACKAGIST = 'https://packagist.org/downloads/';

    private const int MAX_BYTES = 32 * 1024 * 1024;

    /**
     * @param  string|null  $vendor  the vendor folder of the project; null when it could not be found
     */
    public function __construct(private ?string $vendor) {}

    /**
     * The vendor folder of the autoloader that loads Hornet: right wherever the project put it (Bedrock, Pollora, a custom
     * `vendor-dir`), and never a folder guessed above the web root.
     */
    public static function ofProject(): self
    {
        if (class_exists(ClassLoader::class)) {
            foreach (ClassLoader::getRegisteredLoaders() as $vendor => $loader) {
                if ($loader->findFile(self::class) !== false) {
                    return new self((string) $vendor);
                }
            }
        }

        return new self(null);
    }

    public function inventory(): ?ComposerInventory
    {
        if ($this->vendor === null) {
            return null;
        }

        $installed = self::read($this->vendor.'/composer/installed.json');

        if ($installed !== null && is_array($installed['packages'] ?? null)) {
            $dev = is_array($installed['dev-package-names'] ?? null) ? $installed['dev-package-names'] : [];

            return self::sort($installed['packages'], array_values(array_filter($dev, is_string(...))));
        }

        $lock = self::read(dirname($this->vendor).'/composer.lock');

        return $lock !== null && is_array($lock['packages'] ?? null) ? self::sort($lock['packages'], []) : null;
    }

    /**
     * @return array<mixed>|null
     */
    private static function read(string $file): ?array
    {
        if (! is_file($file) || ! is_readable($file) || filesize($file) > self::MAX_BYTES) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<mixed>  $entries
     * @param  list<string>  $dev  names of the development packages
     */
    private static function sort(array $entries, array $dev): ComposerInventory
    {
        $packages = [];
        $branches = [];
        $elsewhere = 0;

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null)) {
                continue;
            }

            $name = $entry['name'];

            if (! self::fromPackagist($entry) || preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#D', $name) !== 1) {
                $elsewhere++;

                continue;
            }

            $version = is_string($entry['version'] ?? null) ? (string) preg_replace('/^v(?=\d)/', '', $entry['version']) : '';

            if (preg_match('/^\d+(\.\d+)*([._+-]?[A-Za-z0-9]+)*$/D', $version) !== 1 || str_ends_with($version, 'dev')) {
                $branches[] = $name;

                continue;
            }

            $packages[] = new ComposerPackage($name, $version, in_array($name, $dev, true));
        }

        return new ComposerInventory($packages, $elsewhere, $branches);
    }

    /**
     * @param  array<mixed>  $entry
     */
    private static function fromPackagist(array $entry): bool
    {
        foreach (['dist', 'source'] as $from) {
            if (is_array($entry[$from] ?? null) && in_array($entry[$from]['type'] ?? null, ['path', 'artifact'], true)) {
                return false;
            }
        }

        return ($entry['notification-url'] ?? null) === self::PACKAGIST;
    }
}

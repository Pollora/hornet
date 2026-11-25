<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * What the files of a plugin that WordPress.org knows nothing of looked like the first time they were seen.
 *
 * There is no reference for such a plugin (a premium one, one installed by Composer from elsewhere): the first look is trusted,
 * and a later change is reported. A plugin that is compromised before that first look cannot be told from a good one.
 */
final readonly class Baseline
{
    /**
     * @param  array<string, string>  $hashes  SHA-256 by path inside the plugin
     */
    public function __construct(
        public string $version,
        public array $hashes,
    ) {}

    /**
     * @return array{version: string, hashes: array<string, string>}
     */
    public function toArray(): array
    {
        return ['version' => $this->version, 'hashes' => $this->hashes];
    }

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value) || ! is_string($value['version'] ?? null) || ! is_array($value['hashes'] ?? null)) {
            return null;
        }

        $hashes = [];

        foreach ($value['hashes'] as $path => $hash) {
            if (is_string($hash)) {
                $hashes[(string) $path] = $hash;
            }
        }

        return new self($value['version'], $hashes);
    }
}

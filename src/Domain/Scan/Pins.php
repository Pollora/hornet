<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * The hashes of the files the owner allowed to differ, as they were when first seen.
 *
 * An allowed file is not trusted for good: it is pinned, and a change after that is reported. Without the pin, the one
 * file that is allowed to differ would be the best place to hide in. A pin holds for one release of the core: an update
 * is a new scope, and pins again.
 */
final readonly class Pins
{
    /**
     * @param  string  $scope  what the hashes belong to (the version and locale of the core)
     * @param  array<string, string>  $hashes  MD5 by path
     */
    public function __construct(
        public string $scope,
        public array $hashes = [],
    ) {}

    /**
     * @return array{scope: string, hashes: array<string, string>}
     */
    public function toArray(): array
    {
        return ['scope' => $this->scope, 'hashes' => $this->hashes];
    }

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value) || ! is_string($value['scope'] ?? null) || ! is_array($value['hashes'] ?? null)) {
            return null;
        }

        $hashes = [];

        foreach ($value['hashes'] as $path => $hash) {
            if (is_string($hash)) {
                $hashes[(string) $path] = $hash;
            }
        }

        return new self($value['scope'], $hashes);
    }
}

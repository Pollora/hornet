<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * The versions that a vulnerability concerns: from one version, up to another. A bound that is not given is open.
 *
 * @internal
 */
final readonly class VersionRange
{
    /**
     * @param  string  $minOperator  `ge` (the default) or `gt`
     * @param  string  $maxOperator  `lt` (the default) or `le`
     */
    public function __construct(
        public ?string $min = null,
        public string $minOperator = 'ge',
        public ?string $max = null,
        public string $maxOperator = 'lt',
    ) {}

    public function contains(string $version): bool
    {
        if ($this->min !== null && ! version_compare($version, $this->min, $this->minOperator === 'gt' ? '>' : '>=')) {
            return false;
        }

        return $this->max === null || version_compare($version, $this->max, $this->maxOperator === 'le' ? '<=' : '<');
    }

    /**
     * The first version that is not concerned any more, when the range ends before the latest one: where to update to.
     * A range that ends at `<= 1.2` has no named fix: the version after 1.2 is not known from here.
     */
    public function fixedIn(): ?string
    {
        return $this->max !== null && $this->maxOperator !== 'le' ? $this->max : null;
    }
}

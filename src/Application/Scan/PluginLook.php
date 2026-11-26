<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use Pollora\Hornet\Domain\Scan\Baseline;
use Pollora\Hornet\Domain\Scan\PluginVerdict;

/**
 * What looking at one plugin came to: a verdict (against a list or against what was first seen), a first look to keep, or the reason it could not be done.
 *
 * @internal
 */
final readonly class PluginLook
{
    private function __construct(
        public ?PluginVerdict $verdict = null,
        public bool $fromBaseline = false,
        public ?Baseline $first = null,
        public ?string $why = null,
    ) {}

    public static function listed(PluginVerdict $verdict): self
    {
        return new self($verdict);
    }

    public static function againstFirstLook(PluginVerdict $verdict): self
    {
        return new self($verdict, true);
    }

    public static function firstLook(Baseline $baseline): self
    {
        return new self(first: $baseline);
    }

    public static function failed(string $why): self
    {
        return new self(why: $why);
    }
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * How much a PHP source looks like a web shell, and why.
 *
 * @internal
 */
final readonly class ScriptScore
{
    /** From this score a file is reported. */
    public const int SUSPICIOUS = 5;

    /** From this score it is reported as high: code that runs what a visitor sends. */
    public const int LIKELY_SHELL = 7;

    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public int $score,
        public array $reasons = [],
    ) {}

    public function isSuspicious(): bool
    {
        return $this->score >= self::SUSPICIOUS;
    }
}

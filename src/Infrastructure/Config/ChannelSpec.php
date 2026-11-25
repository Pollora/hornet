<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

use Pollora\Hornet\Domain\Audit\Severity;

/**
 * One channel as the settings describe it, checked: the secrets are already resolved and wrapped.
 */
final readonly class ChannelSpec
{
    /**
     * @param  array<string, mixed>  $options  `Secret` objects for the secrets, strings, lists and flags otherwise
     */
    public function __construct(
        public string $name,
        public ChannelType $type,
        public Severity $minSeverity,
        public array $options,
    ) {}
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * What the files of one plugin said when compared with a list.
 */
final readonly class PluginVerdict
{
    /**
     * @param  list<string>  $modified
     * @param  list<string>  $missing
     * @param  list<string>  $unreadable
     * @param  list<string>  $unexpectedCode  scripts that the list does not have
     */
    public function __construct(
        public string $slug,
        public int $checked,
        public array $modified = [],
        public array $missing = [],
        public array $unreadable = [],
        public array $unexpectedCode = [],
    ) {}

    public function isClean(): bool
    {
        return $this->modified === [] && $this->missing === [] && $this->unexpectedCode === [];
    }
}

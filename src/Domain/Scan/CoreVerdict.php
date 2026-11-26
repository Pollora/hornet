<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * What the core files said when compared with the official list.
 *
 * @internal
 */
final readonly class CoreVerdict
{
    /**
     * @param  int  $checked  how many official files were compared
     * @param  list<string>  $modified  files that differ from the official one
     * @param  list<string>  $missing  official files that are gone
     * @param  list<string>  $unreadable  official files that exist and cannot be read: not a verdict either way
     * @param  list<string>  $unexpected  files that WordPress does not ship, in the root, wp-admin or wp-includes
     * @param  list<string>  $changedSincePinned  files the owner allowed to differ (a patch) that are no longer what they were when first seen
     * @param  array<string, string>  $patched  files the owner allowed to differ, with their MD5 now: what gets pinned
     */
    public function __construct(
        public int $checked,
        public array $modified = [],
        public array $missing = [],
        public array $unreadable = [],
        public array $unexpected = [],
        public array $changedSincePinned = [],
        public array $patched = [],
    ) {}

    public function isClean(): bool
    {
        return $this->modified === [] && $this->missing === [] && $this->unexpected === [] && $this->changedSincePinned === [];
    }

    /**
     * @return list<string> the unexpected files that PHP would run
     */
    public function unexpectedCode(): array
    {
        return array_values(array_filter($this->unexpected, CoreIntegrity::isScript(...)));
    }
}

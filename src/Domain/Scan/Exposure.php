<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

use Pollora\Hornet\Domain\Audit\Severity;

/**
 * An address that must not answer with what it would hold, and how to tell that it does.
 *
 * An address that answers 200 proves nothing: a site that sends its home page for every unknown address would "expose" everything.
 * So the answer must also look like the file: its start is read against `$signature`.
 */
final readonly class Exposure
{
    public const string SITE = 'site';

    public const string CONTENT = 'content';

    /**
     * @param  string  $root  where the path starts: the address of the site, or of its content folder
     * @param  string  $signature  a pattern the start of the answer matches when it is that file
     */
    public function __construct(
        public string $root,
        public string $path,
        public string $what,
        public Severity $severity,
        public string $signature,
    ) {}

    public function isExposedBy(int $status, string $body): bool
    {
        return $status === 200 && preg_match($this->signature, $body) === 1;
    }
}

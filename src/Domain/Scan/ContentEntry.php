<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * One text of the database that holds something a script would.
 *
 * @internal
 */
final readonly class ContentEntry
{
    /**
     * @param  string  $name  the name of an option; empty for the rest
     * @param  string  $text  the text, or its start and its end when it is very long
     */
    public function __construct(
        public ContentKind $kind,
        public int $id,
        public string $name,
        public string $text,
    ) {}
}

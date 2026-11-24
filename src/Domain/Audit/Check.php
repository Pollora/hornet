<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

/**
 * One thing the audit looks at.
 *
 * A check reads and never writes: it changes nothing on the site, and asks nothing of the network.
 * What needs a request or a write belongs to the scanner, not here.
 */
interface Check
{
    /** Stable identifier, kebab-case: the key of the JSON output, of the Site Health test and of the `ignore` setting. */
    public function id(): string;

    /** What is checked, for a person. */
    public function label(): string;

    public function run(Environment $environment): Finding;
}

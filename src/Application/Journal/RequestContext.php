<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Journal;

use Pollora\Hornet\Domain\Journal\Source;

/**
 * What the journal needs to know about the request it is in: where it came from, who is logged in, and from which address.
 */
interface RequestContext
{
    public function source(): Source;

    /** The number of the person who is logged in, 0 when nobody is. */
    public function userId(): int;

    public function userLogin(): string;

    /** The address of the visitor, as it is (the journal cuts it): empty for the command line and the cron. */
    public function ip(): string;
}

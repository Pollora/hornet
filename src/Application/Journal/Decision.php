<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Journal;

/**
 * What to do with an event that a visitor caused.
 *
 * @internal
 */
enum Decision
{
    /** Write it. */
    case Write;

    /** This network went past its limit: write one event that says so. */
    case FloodNetwork;

    /** The site went past its limit for the hour: write one event that says so. */
    case FloodSite;

    /** Count it, and write nothing. */
    case Drop;
}

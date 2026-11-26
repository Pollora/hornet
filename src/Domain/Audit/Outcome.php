<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

/**
 * What a check could say. `Unknown` is not a pass: the server hides what the check needs (open_basedir, a missing file).
 *
 * @internal
 */
enum Outcome: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Unknown = 'unknown';
}

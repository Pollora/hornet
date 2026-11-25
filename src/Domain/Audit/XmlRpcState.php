<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

/**
 * What XML-RPC really answers. Turning `xmlrpc_enabled` off only stops the methods that need a login: pingbacks and
 * `system.multicall` still answer, so "login disabled" is not "closed".
 */
enum XmlRpcState: string
{
    /** Every method answers. */
    case Open = 'open';

    /** The methods that need a login are refused, the others (pingback.ping…) still answer. */
    case LoginDisabled = 'login-disabled';

    /** No method answers. */
    case Closed = 'closed';
}

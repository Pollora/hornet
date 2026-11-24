<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;

/**
 * XML-RPC lets a script try hundreds of passwords in one request. Most sites do not use it.
 *
 * The check reads the effective state, whoever turned it off.
 */
final readonly class XmlRpcCheck implements Check
{
    public function id(): string
    {
        return 'xml-rpc';
    }

    public function label(): string
    {
        return 'XML-RPC';
    }

    public function run(Environment $environment): Finding
    {
        if (! $environment->xmlRpcEnabled()) {
            return Finding::pass($this->id(), 'XML-RPC is disabled.');
        }

        return Finding::fail($this->id(), Severity::Medium, 'XML-RPC is enabled.', [], "Disable it unless Jetpack or the mobile app needs it: add_filter('xmlrpc_enabled', '__return_false'), or use pollora/headless.");
    }
}

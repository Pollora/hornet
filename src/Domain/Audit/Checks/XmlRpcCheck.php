<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Audit\XmlRpcState;

/**
 * XML-RPC lets a script try hundreds of passwords in one request, and its pingbacks can be turned against other sites.
 * Most sites do not use it.
 *
 * The check reads what the server answers, whoever closed it. Refusing the logins (`xmlrpc_enabled` off) leaves the
 * pingbacks open: that is a half measure, and it says so.
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
        return match ($environment->xmlRpcState()) {
            XmlRpcState::Closed => Finding::pass($this->id(), 'XML-RPC is closed: no method answers.'),
            XmlRpcState::LoginDisabled => Finding::fail($this->id(), Severity::Low, 'XML-RPC refuses logins, but pingbacks and other methods that need none still answer.', [], "Close it altogether with Hornet's `hardening.xmlrpc`, or return an empty list from the `xmlrpc_methods` filter."),
            XmlRpcState::Open => Finding::fail($this->id(), Severity::Medium, 'XML-RPC is open.', [], "Close it unless Jetpack or the mobile app needs it: Hornet's `hardening.xmlrpc` does it, or use pollora/headless."),
        };
    }
}

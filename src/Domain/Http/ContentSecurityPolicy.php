<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Http;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;

/**
 * A Content-Security-Policy, in report-only mode (the browser reports what it would block and blocks nothing) or enforced.
 *
 * Report-only is the way in: a policy written blind breaks a site. The dashboard is left out unless `admin` is set: the
 * block editor and TinyMCE need inline styles and scripts that a strict policy refuses.
 *
 * @internal
 */
final readonly class ContentSecurityPolicy
{
    public string $policy;

    /**
     * @throws InvalidSettingsException
     */
    public function __construct(
        public bool $enforce,
        string $policy,
        ?string $reportUri = null,
        public bool $admin = false,
    ) {
        $policy = rtrim(HeaderText::check('headers.csp.policy', $policy), "; \t");

        if ($reportUri !== null) {
            $uri = HeaderText::check('headers.csp.report_uri', $reportUri);
            $parts = parse_url($uri);

            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '' || str_contains($uri, ';') || str_contains($uri, ' ')) {
                throw InvalidSettingsException::invalid('headers.csp.report_uri', 'an https address');
            }

            if (! str_contains($policy, 'report-uri')) {
                $policy .= '; report-uri '.$uri;
            }
        }

        $this->policy = $policy;
    }

    public function header(): string
    {
        return $this->enforce ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
    }
}

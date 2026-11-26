<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Http;

use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;

/**
 * The security headers to send, and which of them go where.
 *
 * Pure: it knows no WordPress and no Laravel. One adapter sends them from WordPress hooks, another from a Laravel middleware.
 *
 * @internal
 */
final readonly class SecurityHeaders
{
    /** Only the policies that tighten: `unsafe-url` and `no-referrer-when-downgrade` leak more than the browser default. */
    public const array REFERRER_POLICIES = ['no-referrer', 'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'origin', 'origin-when-cross-origin'];

    /**
     * @throws InvalidSettingsException
     */
    public function __construct(
        public bool $contentTypeOptions = false,
        public ?string $referrerPolicy = null,
        public ?string $permissionsPolicy = null,
        public ?Hsts $hsts = null,
        public ?ContentSecurityPolicy $csp = null,
    ) {
        if ($referrerPolicy !== null && ! in_array($referrerPolicy, self::REFERRER_POLICIES, true)) {
            throw InvalidSettingsException::invalid('headers.referrer_policy', 'one of '.implode(', ', self::REFERRER_POLICIES));
        }

        if ($permissionsPolicy !== null) {
            HeaderText::check('headers.permissions_policy', $permissionsPolicy);
        }
    }

    /**
     * Whether any header is asked for at all.
     */
    public function isActive(): bool
    {
        return $this->contentTypeOptions || $this->referrerPolicy !== null || $this->permissionsPolicy !== null || $this->hsts instanceof Hsts || $this->csp instanceof ContentSecurityPolicy;
    }

    /**
     * @param  bool  $secure  whether the request came over HTTPS: HSTS is never sent over plain http, where browsers ignore it
     * @return array<string, string>
     */
    public function for(Context $context, bool $secure): array
    {
        $headers = [];

        if ($this->contentTypeOptions) {
            $headers['X-Content-Type-Options'] = 'nosniff';
        }

        if ($this->referrerPolicy !== null) {
            $headers['Referrer-Policy'] = $this->referrerPolicy;
        }

        if ($this->permissionsPolicy !== null) {
            $headers['Permissions-Policy'] = trim($this->permissionsPolicy);
        }

        if ($this->hsts instanceof Hsts && $secure) {
            $headers['Strict-Transport-Security'] = $this->hsts->value();
        }

        if ($this->csp instanceof ContentSecurityPolicy && ($context === Context::Front || $this->csp->admin)) {
            $headers[$this->csp->header()] = $this->csp->policy;
        }

        return $headers;
    }
}

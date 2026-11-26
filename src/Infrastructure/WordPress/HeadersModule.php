<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Domain\Http\Context;
use Pollora\Hornet\Domain\Http\SecurityHeaders;

/**
 * Sends the security headers from WordPress.
 *
 * WordPress has one hook per place: `wp_headers` for the public site, `admin_init` for the dashboard, `login_init` for
 * the login screen (`send_headers` and `wp_headers` do not fire in the last two). Nothing is sent once output has begun.
 * On Pollora the public site is a Laravel response: a middleware sends the headers there, and skips what was already sent.
 *
 * @internal
 */
final readonly class HeadersModule implements Module
{
    public function __construct(private SecurityHeaders $headers) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addFilter('wp_headers', $this->front(...));
        // After WordPress's own, which is at priority 10 (wp_admin_headers): ours is what the owner asked for and wins
        $hooks->addAction('admin_init', fn () => $this->send(Context::Admin), 20);
        $hooks->addAction('login_init', fn () => $this->send(Context::Login), 20);

        if ($this->headers->referrerPolicy !== null) {
            $policy = $this->headers->referrerPolicy;
            $hooks->addFilter('admin_referrer_policy', static fn (): string => $policy);
        }
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    public function front(array $headers): array
    {
        return [...$headers, ...$this->headers->for(Context::Front, is_ssl())];
    }

    private function send(Context $context): void
    {
        if (headers_sent()) {
            return;
        }

        foreach ($this->headers->for($context, is_ssl()) as $name => $value) {
            // A policy is added to the one WordPress already sends (`frame-ancestors 'self'`, against clickjacking), never put in its place:
            // several policies hold together, each one only narrows what the others allow. The other headers have one value.
            header($name.': '.$value, ! str_starts_with($name, 'Content-Security-Policy'));
        }
    }
}

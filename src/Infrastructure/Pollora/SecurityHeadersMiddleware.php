<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Pollora;

use Closure;
use Pollora\Hornet\Domain\Http\Context;
use Pollora\Hornet\Domain\Http\SecurityHeaders;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The security headers on the responses Laravel builds, which is how Pollora serves the public site.
 *
 * It adds a header only when the response does not carry it and PHP has not sent it already (the WordPress hooks may have):
 * Symfony appends headers when it sends them instead of replacing them, so a double would be sent twice.
 *
 * It has been tested against Symfony responses and has never run inside a Pollora application.
 */
final readonly class SecurityHeadersMiddleware
{
    /**
     * @param  (Closure(): list<string>)|null  $sent  the names of the headers PHP has already sent; `headers_list()` when null
     */
    public function __construct(
        private SecurityHeaders $headers,
        private ?Closure $sent = null,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (! $response instanceof Response) {
            return $response;
        }

        $already = array_map(strtolower(...), $this->sent instanceof Closure ? ($this->sent)() : self::sentHeaders());

        foreach ($this->headers->for(Context::Front, $request->isSecure()) as $name => $value) {
            if (! $response->headers->has($name) && ! in_array(strtolower($name), $already, true)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * @return list<string>
     */
    private static function sentHeaders(): array
    {
        return array_map(static fn (string $line): string => explode(':', $line, 2)[0], headers_list());
    }
}

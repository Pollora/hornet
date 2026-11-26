<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Http\Context;
use Pollora\Hornet\Domain\Http\SecurityHeaders;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Domain\Scan\Pins;

/**
 * Asks the site for its home page and looks at the headers a visitor gets, whoever set them: PHP, the web server or a proxy.
 *
 * It judges three: `nosniff`, a protection against framing, and HSTS when the address is HTTPS. A Content-Security-Policy needs
 * work specific to each site, and browsers already have safe defaults for the referrer: neither is judged. It also says when a
 * header that Hornet is set to send does not arrive, which only a request from outside can show.
 */
final readonly class SecurityHeadersTask implements ScanTask
{
    public const string ID = 'security-headers';

    private const int READ = 1024;

    private const int TIMEOUT = 8;

    /**
     * @param  string  $home  the address of the home page
     * @param  bool  $production  a site that is not in production is not judged, and not asked
     * @param  SecurityHeaders  $configured  what Hornet is set to send
     */
    public function __construct(
        private HttpClient $http,
        private string $home,
        private bool $production,
        private SecurityHeaders $configured,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function run(?Pins $pinned, bool $repin): TaskResult
    {
        return new TaskResult($this->finding());
    }

    private function finding(): Finding
    {
        if (! $this->production) {
            return Finding::pass(self::ID, Message::of('Not a production site: not judged.'));
        }

        try {
            [$response, $address] = $this->homePage();
        } catch (TransportException) {
            return Finding::unknown(self::ID, Message::of('The site did not answer its own address from the server (DNS, a firewall, a certificate that is not trusted): its headers were not seen.'));
        }

        if ($response->status !== 200) {
            return Finding::unknown(self::ID, Message::of('The home page answered %d: its headers were not judged.', $response->status));
        }

        $secure = str_starts_with($address, 'https://');
        $headers = $response->headers;
        $policy = $headers['content-security-policy'] ?? '';
        $missing = [];

        if (stripos($headers['x-content-type-options'] ?? '', 'nosniff') === false) {
            $missing[] = Message::of('X-Content-Type-Options: nosniff is missing (a browser may take a file for something else than it is).');
        }

        if (preg_match('/\b(?:deny|sameorigin)\b/i', $headers['x-frame-options'] ?? '') !== 1 && stripos($policy, 'frame-ancestors') === false) {
            $missing[] = Message::of('Nothing stops another site from showing this one in a frame (X-Frame-Options, or frame-ancestors).');
        }

        if ($secure && preg_match('/max-age\s*=\s*"?[1-9]/i', $headers['strict-transport-security'] ?? '') !== 1) {
            $missing[] = Message::of('Strict-Transport-Security is missing (a browser may still come over plain HTTP).');
        }

        $dropped = [];

        foreach (array_keys($this->configured->for(Context::Front, $secure)) as $name) {
            if (! isset($headers[strtolower($name)])) {
                $dropped[] = Message::of('%s is turned on in the settings of Hornet but does not reach the visitor: something in front (a page cache served without PHP, a proxy) drops it.', $name);
            }
        }

        $count = count($missing) + count($dropped);

        if ($count === 0) {
            return Finding::pass(self::ID, Message::of('The home page sends the security headers that are judged.'));
        }

        return Finding::fail(
            self::ID,
            Severity::Low,
            Message::plural('%d security header problem on the home page.', '%d security header problems on the home page.', $count, $count),
            [...$missing, ...$dropped],
            Message::of("Turn on Hornet's `headers` settings, or set the headers in the web server (`wp hornet server-config nginx --headers`). Against framing, send X-Frame-Options: SAMEORIGIN or a Content-Security-Policy with frame-ancestors 'self'. If a CDN adds them where this server cannot see them, set this finding aside."),
        );
    }

    /**
     * The home page, after one redirection to the same host (a language, a trailing slash, HTTPS): the client follows none.
     *
     * @return array{HttpResponse, string} the answer, and the address that gave it
     *
     * @throws TransportException
     */
    private function homePage(): array
    {
        $address = $this->home;
        $response = $this->ask($address);
        $location = $response->headers['location'] ?? '';

        if ($response->status < 300 || $response->status >= 400 || $location === '') {
            return [$response, $address];
        }

        $host = parse_url($address, PHP_URL_HOST);

        if (str_starts_with($location, '/') && ! str_starts_with($location, '//')) {
            $next = parse_url($address, PHP_URL_SCHEME).'://'.$host.(($port = parse_url($address, PHP_URL_PORT)) === null ? '' : ':'.$port).$location;
        } elseif (preg_match('#^https?://#i', $location) === 1 && parse_url($location, PHP_URL_HOST) === $host) {
            $next = $location;
        } else {
            return [$response, $address];
        }

        return [$this->ask($next), $next];
    }

    /**
     * @throws TransportException
     */
    private function ask(string $address): HttpResponse
    {
        return $this->http->get(Secret::of($address), [], self::READ, self::TIMEOUT, true);
    }
}

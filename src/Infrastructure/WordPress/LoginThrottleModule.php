<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Closure;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\I18n\Translator;
use Pollora\Hornet\Domain\Login\AttemptStore;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Infrastructure\Config\LoginSettings;
use WP_Error;

/**
 * Locks out an address that fails to log in too often. It is the fallback for a site that does not run pollora/portcullis.
 *
 * - **Per address only.** A lock on a user name would let anyone lock the owner out.
 * - **Late.** WordPress's own `authenticate` filters are at priority 20 and ignore an error that comes before them when the
 *   user name and the password are filled in; the lock runs at 100 and turns even a valid user into an error.
 * - **Failures of application passwords count too** (REST, XML-RPC), and a success forgets the failures of the address.
 * - **It never locks an address that many visitors share.** Behind a proxy or a load balancer that is not listed in
 *   `network.trusted_proxies`, every visitor has the proxy's address, and one attacker would lock everybody out: it
 *   steps aside and leaves a note that the audit turns into a high finding.
 * - **A forwarding header from an address that is not a trusted proxy is not believed**, and the request is counted under the
 *   address that opened the connection: otherwise adding a header would be a way around the lock.
 * - `network`: the addresses in `login.allow_ips` are never counted nor locked.
 *
 * @internal
 */
final readonly class LoginThrottleModule implements Module
{
    public const string PROBLEM_OPTION = 'hornet_throttle_problem';

    /**
     * @param  Closure(): ?ClientIp  $client  who is asking; null when nobody is (a command line)
     * @param  Closure(string): void  $problem  where a reason to doubt the throttle is written down
     * @param  string  $salt  what the stored addresses are hashed with
     */
    public function __construct(
        private LoginSettings $settings,
        private AttemptStore $store,
        private Clock $clock,
        private Closure $client,
        private Closure $problem,
        private string $salt,
        private ?Translator $translator = null,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addFilter('authenticate', $this->gate(...), 100, 1);
        $hooks->addAction('wp_login_failed', $this->failed(...));
        $hooks->addAction('application_password_failed_authentication', $this->applicationPasswordFailed(...));
        // The REST API checks application passwords without going through `authenticate`: the lock is applied there too
        $hooks->addAction('wp_authenticate_application_password_errors', $this->gateApplicationPassword(...));
        $hooks->addAction('wp_login', $this->succeeded(...));
    }

    /**
     * @param  mixed  $user  a WP_User, a WP_Error or null
     */
    public function gate(mixed $user): mixed
    {
        $client = $this->counted();

        if (! $client instanceof ClientIp) {
            return $user;
        }

        $refusal = $this->refusal($client);

        return $refusal === null ? $user : new WP_Error('hornet_locked', $refusal);
    }

    /**
     * Refuses an application password, even the right one, to an address that is locked.
     *
     * @param  mixed  $error  the WP_Error WordPress hands over: what is added to it refuses the authentication
     */
    public function gateApplicationPassword(mixed $error): void
    {
        $client = $this->counted();
        $refusal = $client instanceof ClientIp ? $this->refusal($client) : null;

        if ($refusal !== null && $error instanceof WP_Error) {
            $error->add('hornet_locked', $refusal);
        }
    }

    /**
     * @param  mixed  $error  why WordPress refused it
     */
    public function applicationPasswordFailed(mixed $error = null): void
    {
        // Refused by this lock: not one more guess, or a person waiting with the right password would lengthen their own wait
        if ($error instanceof WP_Error && $error->get_error_code() === 'hornet_locked') {
            return;
        }

        $this->failed();
    }

    private function refusal(ClientIp $client): ?string
    {
        $now = $this->clock->now()->getTimestamp();
        $attempts = $this->store->get($client->key($this->salt));

        if (! $this->settings->policy->isLocked($attempts, $now)) {
            return null;
        }

        return sprintf(
            '<strong>%s</strong> %s',
            Message::of('Error:')->render($this->translator),
            Message::of('Too many failed login attempts. Try again in %d minute(s).', (int) ceil($this->settings->policy->secondsLeft($attempts, $now) / 60))->render($this->translator),
        );
    }

    public function failed(): void
    {
        $client = $this->counted();

        if (! $client instanceof ClientIp) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $key = $client->key($this->salt);
        $after = $this->settings->policy->failed($this->store->get($key), $now);

        // A day on top of the lock: the memory of past lockouts is what makes the next one longer
        $this->store->put($key, $after, max($this->settings->policy->window, $after->lockedUntil - $now) + 86400);
    }

    public function succeeded(): void
    {
        $client = $this->counted();

        if (! $client instanceof ClientIp) {
            return;
        }

        $key = $client->key($this->salt);
        $this->store->put($key, $this->settings->policy->succeeded($this->store->get($key)), 86400);
    }

    /**
     * The address to count, or null when this request is not one to count: no visitor, an address on the allow list, or an
     * address that many visitors share (which is written down as a problem).
     */
    private function counted(): ?ClientIp
    {
        $client = ($this->client)();

        if (! $client instanceof ClientIp || $this->settings->isAllowed($client->address)) {
            return null;
        }

        if ($client->isSharedAddress()) {
            ($this->problem)('shared');

            return null;
        }

        if ($client->forwardedIgnored) {
            ($this->problem)('forwarded-untrusted');
        }

        return $client;
    }
}

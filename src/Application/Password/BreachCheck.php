<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Password;

use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\Secret;

/**
 * Asks Have I Been Pwned whether a password is in a known breach, without telling it the password.
 *
 * Only the first five characters of the SHA-1 of the password leave the site (k-anonymity): the service answers with every
 * suffix that shares them, and the comparison is made here. The request is padded (`Add-Padding`), so the size of the answer
 * does not say how many hashes matched; padding entries come back with a count of 0, which is not a breach.
 *
 * It fails open: a service that is down, slow or odd must never stop someone from setting a password. It is asked at the moment
 * a password is chosen, never at login.
 *
 * @internal
 */
final readonly class BreachCheck
{
    private const string ENDPOINT = 'https://api.pwnedpasswords.com/range/';

    /** A range answer is about 30 KB with padding; this leaves room and still bounds what is read. */
    private const int MAX_BYTES = 262144;

    /** Seconds: a slow service must not hold up the saving of a profile. */
    private const int TIMEOUT = 4;

    public function __construct(private HttpClient $http) {}

    /**
     * @return int|null how many times the password appears in known breaches (0 when it does not); null when it could not be found out
     */
    public function occurrences(string $password): ?int
    {
        if ($password === '') {
            return null;
        }

        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = $this->http->get(Secret::of(self::ENDPOINT.$prefix), ['Add-Padding' => 'true'], self::MAX_BYTES, self::TIMEOUT);
        } catch (TransportException) {
            return null;
        }

        if ($response->status !== 200) {
            return null;
        }

        foreach (preg_split('/\r\n|\n|\r/', $response->body) ?: [] as $line) {
            [$candidate, $count] = [...explode(':', trim($line), 2), '0'];

            if (strtoupper($candidate) === $suffix && ctype_digit($count)) {
                return (int) $count;
            }
        }

        return 0;
    }
}

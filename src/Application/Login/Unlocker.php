<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Login;

use Pollora\Hornet\Domain\Login\AttemptStore;
use Pollora\Hornet\Domain\Network\Cidr;
use Pollora\Hornet\Domain\Network\ClientIp;

/**
 * The way back in: forgets the failures and the lockout of one address, or of every address.
 *
 * It is a command-line tool on purpose: a person who is locked out can still reach the server, and nothing on the web can unlock.
 */
final readonly class Unlocker
{
    public function __construct(
        private AttemptStore $store,
        private string $salt,
    ) {}

    /**
     * @return bool false when the text is not an IP address
     */
    public function unlock(string $address): bool
    {
        if (Cidr::pack($address) === null) {
            return false;
        }

        $this->store->forget((new ClientIp($address, $address))->key($this->salt));

        return true;
    }

    /**
     * @return int how many addresses were locked
     */
    public function unlockAll(): int
    {
        return $this->store->forgetAll();
    }
}

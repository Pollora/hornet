<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Scan\CoreChecksums;

final class FakeCoreChecksums implements CoreChecksums
{
    /** @var list<array{string, string}> */
    public array $asked = [];

    /**
     * @param  array<string, string>|null  $list
     */
    public function __construct(
        public ?array $list = [],
        public ?TransportException $failure = null,
    ) {}

    public function checksums(string $version, string $locale): ?array
    {
        $this->asked[] = [$version, $locale];

        return $this->failure instanceof TransportException ? throw $this->failure : $this->list;
    }
}

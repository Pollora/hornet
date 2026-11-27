<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * @internal
 */
interface AdvisoryCache
{
    /**
     * @return array<mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * @param  array<mixed>  $value
     * @param  int  $seconds  how long it may be used
     */
    public function put(string $key, array $value, int $seconds): void;
}

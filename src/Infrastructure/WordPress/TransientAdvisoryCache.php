<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Domain\Scan\AdvisoryCache;

/**
 * What api.osv.dev said, in transients (site transients on a network: the files are shared). Stored as JSON.
 *
 * @internal
 */
final readonly class TransientAdvisoryCache implements AdvisoryCache
{
    public function get(string $key): ?array
    {
        $stored = get_site_transient($this->name($key));
        $data = is_string($stored) ? json_decode($stored, true) : null;

        return is_array($data) ? $data : null;
    }

    public function put(string $key, array $value, int $seconds): void
    {
        set_site_transient($this->name($key), json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $seconds);
    }

    private function name(string $key): string
    {
        return 'hornet_osv_'.md5($key);
    }
}

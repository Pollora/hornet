<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * A plugin that lives in its own folder of the plugins directory. The folder name is its slug on WordPress.org, when it comes
 * from there: the header may say it is updated from somewhere else.
 *
 * @internal
 */
final readonly class InstalledPlugin
{
    /**
     * @param  string  $name  the `Plugin Name` header
     * @param  string  $updateUri  the `Update URI` header
     */
    public function __construct(
        public string $slug,
        public string $version,
        public string $name = '',
        public string $updateUri = '',
    ) {}

    /**
     * Whether the header says updates do not come from WordPress.org. WordPress reads it the same way: a value whose host is
     * not wordpress.org or w.org (a vendor's address, or `false`) takes the plugin out of the directory's updates.
     */
    public function updatedElsewhere(): bool
    {
        $uri = trim($this->updateUri);

        if ($uri === '') {
            return false;
        }

        $host = parse_url(str_contains($uri, '://') ? $uri : 'https://'.$uri, PHP_URL_HOST);

        return ! is_string($host) || ! in_array(strtolower($host), ['wordpress.org', 'w.org'], true);
    }
}

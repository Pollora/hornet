<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Scan\PluginChecksums;

final class FakePluginChecksums implements PluginChecksums
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param  array<string, array<string, list<string>>|TransportException>  $lists  by "slug/version"; a plugin that is not there has no list (404)
     */
    public function __construct(public array $lists = [], private readonly ?FakeClock $clock = null, private readonly string $takes = 'PT0S') {}

    public function checksums(string $slug, string $version): ?array
    {
        $this->asked[] = $slug.'/'.$version;
        $this->clock?->advance($this->takes);
        $list = $this->lists[$slug.'/'.$version] ?? null;

        return $list instanceof TransportException ? throw $list : $list;
    }
}

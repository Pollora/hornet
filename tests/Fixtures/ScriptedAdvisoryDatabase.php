<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Closure;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Scan\Advisory;
use Pollora\Hornet\Domain\Scan\AdvisoryDatabase;

/**
 * A database that answers what a test wrote.
 */
final class ScriptedAdvisoryDatabase implements AdvisoryDatabase
{
    /** @var list<list<string>> the packages of each batch, as name@version */
    public array $batches = [];

    /** @var list<string> */
    public array $read = [];

    /**
     * @param  array<string, list<string>>  $matches  name@version => ids
     * @param  array<string, Advisory|TransportException>  $records
     * @param  Closure(): mixed|null  $onAsk
     */
    public function __construct(
        public array $matches = [],
        public array $records = [],
        public bool $down = false,
        private readonly ?Closure $onAsk = null,
    ) {}

    public function affecting(array $packages): array
    {
        $names = array_map(static fn ($package): string => $package->name.'@'.$package->version, $packages);
        $this->batches[] = $names;
        $this->tick();

        if ($this->down) {
            throw new TransportException('down');
        }

        return array_map(fn (string $name): array => array_fill_keys($this->matches[$name] ?? [], '2026-01-01T00:00:00Z'), $names);
    }

    public function advisory(string $id, string $modified): Advisory
    {
        $this->read[] = $id;
        $this->tick();
        $record = $this->records[$id] ?? new TransportException('missing');

        return $record instanceof TransportException ? throw $record : $record;
    }

    private function tick(): void
    {
        if ($this->onAsk instanceof Closure) {
            ($this->onAsk)();
        }
    }
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Scan;

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\HttpResponse;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Domain\Scan\Advisory;
use Pollora\Hornet\Domain\Scan\AdvisoryDatabase;
use Pollora\Hornet\Infrastructure\Notification\Text;

/**
 * OSV (https://google.github.io/osv.dev/api/): `POST /v1/querybatch` says which records concern each name and version of the
 * Packagist ecosystem (ids only, in the order asked, with a page token when there are more), `GET /v1/vulns/{id}` gives a record.
 *
 * An answer that does not have that shape is "could not ask". What a record writes is data from outside.
 *
 * @internal
 */
final readonly class OsvAdvisoryDatabase implements AdvisoryDatabase
{
    private const string URL = 'https://api.osv.dev/v1/';

    private const string ECOSYSTEM = 'Packagist';

    private const int MAX_BYTES = 8 * 1024 * 1024;

    private const int PAGES = 10;

    private const int TIMEOUT = 15;

    private const string ID = '/^[A-Za-z0-9][A-Za-z0-9._-]{2,80}$/D';

    public function __construct(private HttpClient $http) {}

    public function affecting(array $packages): array
    {
        $found = array_fill(0, count($packages), []);
        $queries = [];

        foreach ($packages as $index => $package) {
            $queries[$index] = ['package' => ['ecosystem' => self::ECOSYSTEM, 'name' => $package->name], 'version' => $package->version];
        }

        for ($page = 0; $queries !== []; $page++) {
            if ($page === self::PAGES) {
                throw new TransportException('api.osv.dev has more pages than are read.');
            }

            $response = $this->http->post(Secret::of(self::URL.'querybatch'), ['Content-Type' => 'application/json', 'Accept' => 'application/json'], json_encode(['queries' => array_values($queries)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), false, self::MAX_BYTES, self::TIMEOUT);
            $results = self::decode($response)['results'] ?? null;

            if (! is_array($results) || count($results) !== count($queries)) {
                throw new TransportException('api.osv.dev did not answer each question.');
            }

            $next = [];

            foreach (array_keys($queries) as $position => $index) {
                $result = $results[$position] ?? null;

                if (! is_array($result) || ! is_array($result['vulns'] ?? [])) {
                    throw new TransportException('api.osv.dev sent a result that cannot be read.');
                }

                foreach ($result['vulns'] ?? [] as $record) {
                    $id = is_array($record) ? ($record['id'] ?? null) : null;

                    if (! is_string($id) || preg_match(self::ID, $id) !== 1) {
                        throw new TransportException('api.osv.dev sent a record without a usable identifier.');
                    }

                    $found[$index][$id] = is_string($record['modified'] ?? null) ? $record['modified'] : '';
                }

                if (is_string($result['next_page_token'] ?? null) && $result['next_page_token'] !== '') {
                    $next[$index] = [...$queries[$index], 'page_token' => $result['next_page_token']];
                }
            }

            $queries = $next;
        }

        return array_values($found);
    }

    public function advisory(string $id, string $modified): Advisory
    {
        if (preg_match(self::ID, $id) !== 1) {
            throw new TransportException('Not an identifier of api.osv.dev.');
        }

        $data = self::decode($this->http->get(Secret::of(self::URL.'vulns/'.rawurlencode($id)), ['Accept' => 'application/json'], self::MAX_BYTES, self::TIMEOUT));

        if (($data['id'] ?? null) !== $id) {
            throw new TransportException('api.osv.dev sent another record than the one asked.');
        }

        $aliases = array_values(array_filter(is_array($data['aliases'] ?? null) ? $data['aliases'] : [], static fn (mixed $alias): bool => is_string($alias) && preg_match(self::ID, $alias) === 1));
        $level = is_array($data['database_specific'] ?? null) ? ($data['database_specific']['severity'] ?? null) : null;

        return new Advisory(
            $id,
            $aliases,
            is_string($data['summary'] ?? null) ? Text::truncate(Text::clean($data['summary']), 160) : '',
            // `severity[].score` is a CVSS vector, not a number: the word GitHub gives is used, and without one it is medium, never low
            match (is_string($level) ? strtoupper($level) : '') {
                'CRITICAL' => Severity::Critical,
                'HIGH' => Severity::High,
                'LOW' => Severity::Low,
                default => Severity::Medium,
            },
            self::ranges($data['affected'] ?? null),
            is_string($data['withdrawn'] ?? null),
        );
    }

    /**
     * @return array<mixed>
     */
    private static function decode(HttpResponse $response): array
    {
        $data = $response->status === 200 ? json_decode($response->body, true) : null;

        return is_array($data) ? $data : throw new TransportException(sprintf('api.osv.dev sent HTTP %d and nothing that can be read.', $response->status));
    }

    /**
     * The ordered stretches of releases that are concerned, for each Packagist package of the record.
     *
     * @return list<array{package: string, events: list<array{string, string}>}>
     */
    private static function ranges(mixed $affected): array
    {
        $ranges = [];

        foreach (is_array($affected) ? $affected : [] as $entry) {
            $package = is_array($entry) && is_array($entry['package'] ?? null) ? $entry['package'] : [];

            if (! is_array($entry) || ($package['ecosystem'] ?? null) !== self::ECOSYSTEM || ! is_string($package['name'] ?? null)) {
                continue;
            }

            foreach (is_array($entry['ranges'] ?? null) ? $entry['ranges'] : [] as $range) {
                if (! is_array($range) || ($range['type'] ?? null) !== 'ECOSYSTEM' || ! is_array($range['events'] ?? null)) {
                    continue;
                }

                $events = [];

                foreach ($range['events'] as $event) {
                    foreach (['introduced', 'fixed', 'last_affected'] as $kind) {
                        $version = is_array($event) ? ($event[$kind] ?? null) : null;

                        if (is_string($version) && preg_match('/^v?[0-9][A-Za-z0-9._+-]*$/D', $version) === 1) {
                            $events[] = [$kind, ltrim($version, 'v')];
                        }
                    }
                }

                $ranges[] = ['package' => $package['name'], 'events' => $events];
            }
        }

        return $ranges;
    }
}

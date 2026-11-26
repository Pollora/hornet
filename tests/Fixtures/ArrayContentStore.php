<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Scan\ContentEntry;
use Pollora\Hornet\Domain\Scan\ContentKind;
use Pollora\Hornet\Domain\Scan\ContentRules;
use Pollora\Hornet\Domain\Scan\ContentStore;

/** A database made of arrays, that remembers what was asked of it. */
final class ArrayContentStore implements ContentStore
{
    /** @var list<array{string, int, int}> */
    public array $ranges = [];

    /** @var list<int> */
    public array $batches = [];

    /**
     * @param  array<string, array<int, string|array{ContentKind, string, string}>>  $tables  by source, then by identifier: a text, or a kind, a name and a text
     * @param  \Closure(): void|null  $onRange  called at each range, to move a clock
     */
    public function __construct(public array $tables, private readonly ?\Closure $onRange = null) {}

    public function sources(): array
    {
        return array_keys($this->tables);
    }

    public function next(string $source, int $after): ?int
    {
        $ids = array_filter(array_keys($this->tables[$source]), static fn (int $id): bool => $id > $after);

        return $ids === [] ? null : min($ids);
    }

    public function matches(string $source, int $from, int $to): array
    {
        $this->ranges[] = [$source, $from, $to];
        $this->onRange?->__invoke();
        $ids = [];

        foreach ($this->tables[$source] as $id => $row) {
            $text = is_array($row) ? $row[2] : $row;

            foreach (ContentRules::MARKS as $mark) {
                if ($id >= $from && $id <= $to && stripos($text, $mark) !== false) {
                    $ids[] = $id;

                    break;
                }
            }
        }

        return $ids;
    }

    public function entries(string $source, array $ids): array
    {
        $this->batches[] = count($ids);

        return array_map(function (int $id) use ($source): ContentEntry {
            $row = $this->tables[$source][$id];
            [$kind, $name, $text] = is_array($row) ? $row : [ContentKind::Post, '', $row];

            return new ContentEntry($kind, $id, $name, $text);
        }, $ids);
    }
}

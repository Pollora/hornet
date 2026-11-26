<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * The texts of the database, read in small ranges of identifiers: a query never reads more than its range, however large the table.
 * It only reads.
 */
interface ContentStore
{
    /**
     * The tables that are read, by a short name, in the order they are read.
     *
     * @return list<string>
     */
    public function sources(): array;

    /** The first identifier after this one, null when there is none: gaps are jumped over. */
    public function next(string $source, int $after): ?int;

    /**
     * The identifiers of this range whose text holds one of {@see ContentRules::MARKS}. No text is read into PHP.
     *
     * @return list<int>
     */
    public function matches(string $source, int $from, int $to): array;

    /**
     * @param  list<int>  $ids  a few of them: their texts are held in memory together
     * @return list<ContentEntry>
     */
    public function entries(string $source, array $ids): array;
}

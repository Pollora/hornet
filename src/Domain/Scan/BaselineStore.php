<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

interface BaselineStore
{
    /**
     * @return array<string, Baseline> by slug
     */
    public function load(): array;

    /**
     * @param  array<string, Baseline>  $baselines
     */
    public function save(array $baselines): void;
}

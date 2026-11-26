<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

/**
 * @internal
 */
interface SetAsideStore
{
    /**
     * @return array<string, SetAside> by the id of the check
     */
    public function all(): array;

    public function put(SetAside $aside): void;

    public function remove(string $check): void;
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

use DateTimeImmutable;

/**
 * What a check may know about the site. Every method reads; none changes anything.
 */
interface Environment
{
    /** Whether the constant is defined, whatever its value. */
    public function isDefined(string $constant): bool;

    /** The value of the constant, null when it is not defined. */
    public function constant(string $name): mixed;

    /** `production`, `staging`, `development` or `local`. */
    public function environmentType(): string;

    public function phpVersion(): string;

    public function wordpressVersion(): string;

    /** The version of the update WordPress last found, null when none is waiting. */
    public function availableCoreUpdate(): ?string;

    /** Whether the public address of the site starts with https. */
    public function usesHttps(): bool;

    /** The path of wp-config.php, null when it cannot be found or read. */
    public function configFile(): ?string;

    /** The permission bits of a file (0644), null when the server does not tell. */
    public function permissions(string $path): ?int;

    public function userExists(string $login): bool;

    /** Whether XML-RPC answers, once every filter has run. */
    public function xmlRpcEnabled(): bool;

    public function isActive(Component $component): bool;

    public function now(): DateTimeImmutable;
}

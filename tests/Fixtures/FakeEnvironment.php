<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use DateTimeImmutable;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\XmlRpcState;

/**
 * A site described in a few lines: the defaults are a healthy production site, a test changes what it is about.
 */
class FakeEnvironment implements Environment
{
    /**
     * @param  array<string, mixed>  $constants
     * @param  list<Component>  $active
     * @param  list<string>  $users
     */
    public function __construct(
        public array $constants = [],
        public string $type = 'production',
        public string $php = '8.4.1',
        public string $wordpress = '7.1.5',
        public ?string $update = null,
        public bool $https = true,
        public ?string $config = '/srv/wp-config.php',
        public ?int $mode = 0o640,
        public array $users = ['editor'],
        public bool|XmlRpcState $xmlRpc = false,
        public array $active = [],
        public string $today = '2026-11-24',
        public ?bool $usersListed = false,
        public ?bool $editingBlocked = null,
        public ?string $throttleProblem = null,
        /** @var list<int>|null */
        public ?array $withoutTwoFactor = [],
    ) {}

    /**
     * The eight keys and salts, long enough: a site without them fails the salts check, at high severity.
     *
     * @return array<string, string>
     */
    public static function salts(): array
    {
        return array_fill_keys(['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'], str_repeat('k', 40));
    }

    /**
     * @param  array<string, mixed>  $constants
     */
    public static function with(array $constants = [], string $type = 'production'): self
    {
        return new self(constants: $constants, type: $type);
    }

    public function isDefined(string $constant): bool
    {
        return array_key_exists($constant, $this->constants);
    }

    public function constant(string $name): mixed
    {
        return $this->constants[$name] ?? null;
    }

    public function environmentType(): string
    {
        return $this->type;
    }

    public function phpVersion(): string
    {
        return $this->php;
    }

    public function wordpressVersion(): string
    {
        return $this->wordpress;
    }

    public function availableCoreUpdate(): ?string
    {
        return $this->update;
    }

    public function usesHttps(): bool
    {
        return $this->https;
    }

    public function configFile(): ?string
    {
        return $this->config;
    }

    public function permissions(string $path): ?int
    {
        return $this->mode;
    }

    public function userExists(string $login): bool
    {
        return in_array($login, $this->users, true);
    }

    public function xmlRpcState(): XmlRpcState
    {
        return match (true) {
            $this->xmlRpc instanceof XmlRpcState => $this->xmlRpc,
            $this->xmlRpc => XmlRpcState::Open,
            default => XmlRpcState::Closed,
        };
    }

    public function fileEditingBlocked(): bool
    {
        return $this->editingBlocked ?? ($this->constants['DISALLOW_FILE_EDIT'] ?? false) === true || ($this->constants['DISALLOW_FILE_MODS'] ?? false) === true;
    }

    public function usersListedToVisitors(): ?bool
    {
        return $this->usersListed;
    }

    public function usersWithoutTwoFactor(): ?array
    {
        return $this->withoutTwoFactor;
    }

    public function loginThrottleProblem(): ?string
    {
        return $this->throttleProblem;
    }

    public function isActive(Component $component): bool
    {
        return in_array($component, $this->active, true);
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->today.' 12:00:00');
    }
}

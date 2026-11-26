<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

use DateTimeImmutable;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;

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

    /** What XML-RPC answers, once every filter has run. */
    public function xmlRpcState(): XmlRpcState;

    /** Whether the theme and plugin editor is refused to everyone (`DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, or a filter), once every filter has run. */
    public function fileEditingBlocked(): bool;

    /**
     * Whether an anonymous visitor gets the list of users from the REST API. Null when it could not be found out.
     */
    public function usersListedToVisitors(): ?bool;

    /**
     * Why the login throttle cannot be trusted, when it met a request it could not place: `shared` (the address of every visitor
     * is the proxy's, so it refused to lock anybody) or `forwarded-untrusted` (a forwarding header came from an address that is
     * not a listed proxy). Null when nothing was noted.
     *
     * @return 'shared'|'forwarded-untrusted'|null
     */
    public function loginThrottleProblem(): ?string;

    /**
     * The numbers of the users of the roles Hornet is told to watch (administrators unless `two_factor.require_roles` says otherwise)
     * who have no second factor. Null when the Two-Factor plugin is not there to ask.
     *
     * @return list<int>|null
     */
    public function usersWithoutTwoFactor(): ?array;

    /**
     * The role a visitor who registers gets, with what it may do. Null when registering gives nobody a role: it is closed, or the
     * site is part of a network, where signing up gives no role on a site.
     *
     * @return array{role: string, capabilities: list<string>}|null
     */
    public function registrationRole(): ?array;

    /**
     * The application passwords of the accounts that can manage the site (timestamps). Null when application passwords are turned off.
     *
     * @return list<array{user: int, created: int, last_used: int|null}>|null
     */
    public function administratorApplicationPasswords(): ?array;

    /**
     * Whether PHP shows its errors on a web page, as last seen from the web server (the command line has its own setting).
     * Null when it was never seen.
     */
    public function displaysPhpErrors(): ?bool;

    /**
     * The plugin updates WordPress last found, and when it looked (a timestamp). `unfollowed` counts the installed plugins that
     * WordPress.org does not know. Null when WordPress never looked.
     *
     * @return array{checked: int, waiting: list<array{slug: string, installed: string, available: string}>, unfollowed: int}|null
     */
    public function pluginUpdates(): ?array;

    /**
     * The same for the themes. Those written for the site (`scanner.own_themes`) are left out.
     *
     * @return array{checked: int, waiting: list<array{slug: string, installed: string, available: string}>, unfollowed: int}|null
     */
    public function themeUpdates(): ?array;

    /**
     * The plugins that are installed and not active. Null on a network, where another site may use them.
     *
     * @return list<string>|null
     */
    public function inactivePlugins(): ?array;

    /**
     * The themes that are neither the active one, nor its parent, nor the default theme to fall back on. Null on a network.
     *
     * @return list<string>|null
     */
    public function unusedThemes(): ?array;

    /**
     * What the last scan found, null when the site was never scanned. It is read from what the scan stored: nothing is scanned here.
     */
    public function lastScan(): ?ScanSnapshot;

    public function isActive(Component $component): bool;

    public function now(): DateTimeImmutable;
}

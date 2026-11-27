<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\XmlRpcState;
use Pollora\Hornet\Domain\Journal\EventTypes;
use Pollora\Hornet\Domain\Journal\JournalQuery;
use Pollora\Hornet\Domain\Scalar;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TableJournal;
use Throwable;
use WP_Application_Passwords;
use WP_REST_Request;
use WP_Session_Tokens;
use WP_Theme;

/**
 * What the checks may know, read from a running WordPress. It reads; it writes nothing and asks nothing of the network.
 *
 * @internal
 */
final class WordPressEnvironment implements Environment
{
    /**
     * @param  Settings|null  $settings  what Hornet is set to do: the audit asks whether the throttle is on
     */
    public function __construct(private readonly ?Settings $settings = null) {}

    public function isDefined(string $constant): bool
    {
        return defined($constant);
    }

    public function constant(string $name): mixed
    {
        return defined($name) ? constant($name) : null;
    }

    public function environmentType(): string
    {
        return wp_get_environment_type();
    }

    public function phpVersion(): string
    {
        return PHP_VERSION;
    }

    public function wordpressVersion(): string
    {
        return wp_get_wp_version();
    }

    public function availableCoreUpdate(): ?string
    {
        $updates = get_site_transient('update_core');

        if (! is_object($updates) || ! is_array($updates->updates ?? null)) {
            return null;
        }

        foreach ($updates->updates as $update) {
            if (is_object($update) && ($update->response ?? '') === 'upgrade' && is_string($update->current ?? null)) {
                return $update->current;
            }
        }

        return null;
    }

    public function usesHttps(): bool
    {
        return str_starts_with(home_url(), 'https://');
    }

    public function configFile(): ?string
    {
        // wp-config.php may sit one level above the WordPress root, and open_basedir may hide it
        foreach ([ABSPATH.'wp-config.php', dirname(ABSPATH).'/wp-config.php'] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function permissions(string $path): ?int
    {
        $mode = is_file($path) ? fileperms($path) : false;

        return $mode === false ? null : $mode & 0o777;
    }

    public function userExists(string $login): bool
    {
        return get_user_by('login', $login) !== false;
    }

    public function xmlRpcState(): XmlRpcState
    {
        // A method standing in for the real list: whatever empties the filter closes them all
        $methods = apply_filters('xmlrpc_methods', ['pingback.ping' => 'this:pingback_ping']);

        return match (true) {
            $methods === [] => XmlRpcState::Closed,
            ! apply_filters('xmlrpc_enabled', true) => XmlRpcState::LoginDisabled,
            default => XmlRpcState::Open,
        };
    }

    public function fileEditingBlocked(): bool
    {
        return in_array('do_not_allow', map_meta_cap('edit_plugins', 0), true) && in_array('do_not_allow', map_meta_cap('edit_themes', 0), true);
    }

    /**
     * Asks the REST API for the users as a visitor would, in this process: no network, nothing written.
     */
    public function usersListedToVisitors(): ?bool
    {
        // The request below is an internal one, which a closed REST API does not stop: ask what a visitor's HTTP request would be told
        if ($this->settings instanceof Settings && $this->settings->enabled && $this->settings->hardening->restrictRestApi
            && ! $this->settings->hardening->restAllowList($this->isActive(Component::Headless))->allows('/wp/v2/users')) {
            return false;
        }

        $previous = get_current_user_id();

        try {
            wp_set_current_user(0);
            $response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/users'));
            $data = $response->get_data();

            return $response->get_status() < 400 && is_array($data) && $data !== [];
        } catch (Throwable) {
            return null;
        } finally {
            wp_set_current_user($previous);
        }
    }

    /**
     * A component counts as active when its code is loaded: an installed package that Composer autoloads is on.
     */
    public function isActive(Component $component): bool
    {
        return match ($component) {
            Component::Portcullis => class_exists('Pollora\\Portcullis\\Portcullis'),
            Component::Headless => class_exists('Pollora\\Headless\\Infrastructure\\WordPress\\Bootstrap'),
            Component::HornetThrottle => $this->settings instanceof Settings && $this->settings->login->throttle,
            Component::TwoFactor => class_exists('Two_Factor_Core'),
        };
    }

    public function usersWithoutTwoFactor(): ?array
    {
        $enrollment = new TwoFactorEnrollment;

        if (! $enrollment->available()) {
            return null;
        }

        $roles = $this->settings instanceof Settings && $this->settings->accounts->requireRoles !== [] ? $this->settings->accounts->requireRoles : ['administrator'];
        $ids = get_users(['role__in' => $roles, 'fields' => 'ID', 'number' => 500]);

        return array_values(array_filter(self::numbers($ids), static fn (int $id): bool => ! $enrollment->enrolled($id)));
    }

    public function loginThrottleProblem(): ?string
    {
        return (new ThrottleProblemNote)->read();
    }

    public function registrationRole(): ?array
    {
        if (is_multisite() || ! get_option('users_can_register')) {
            return null;
        }

        $name = get_option('default_role');

        if (! is_string($name) || $name === '') {
            return null;
        }

        $role = get_role($name);

        return $role === null ? null : ['role' => $name, 'capabilities' => array_map(strval(...), array_keys(array_filter($role->capabilities)))];
    }

    /**
     * Core also asks whether the current request is HTTPS, which the command line never is: the address of the site is asked
     * instead, so that the audit says the same thing wherever it runs.
     */
    public function administratorApplicationPasswords(): ?array
    {
        if (! apply_filters('wp_is_application_passwords_available', $this->usesHttps() || wp_get_environment_type() === 'local')) {
            return null;
        }

        $ids = get_users(['capability' => 'manage_options', 'meta_key' => '_application_passwords', 'fields' => 'ID', 'number' => 500]);
        $passwords = [];

        foreach (self::numbers($ids) as $id) {
            foreach (WP_Application_Passwords::get_user_application_passwords($id) as $password) {
                $passwords[] = [
                    'user' => $id,
                    'created' => Scalar::int($password['created'] ?? null),
                    'last_used' => isset($password['last_used']) ? Scalar::int($password['last_used']) : null,
                ];
            }
        }

        return $passwords;
    }

    public function displaysPhpErrors(): ?bool
    {
        return (new ErrorDisplayNote)->read();
    }

    public function pluginUpdates(): ?array
    {
        $installed = [];

        foreach ($this->installedPlugins() as $file => $data) {
            $file = (string) $file;
            $installed[$file] = ['slug' => $this->pluginSlug($file), 'version' => Scalar::string($data['Version'] ?? null)];
        }

        return $this->updates('update_plugins', $installed);
    }

    public function themeUpdates(): ?array
    {
        $own = $this->settings instanceof Settings ? $this->settings->scanner->ownThemes : [];
        $installed = [];

        foreach (wp_get_themes() as $slug => $theme) {
            $slug = (string) $slug;
            $version = $theme->get('Version');

            if (! in_array($slug, $own, true)) {
                $installed[$slug] = ['slug' => $slug, 'version' => is_string($version) ? $version : ''];
            }
        }

        return $this->updates('update_themes', $installed);
    }

    public function inactivePlugins(): ?array
    {
        if (is_multisite()) {
            return null;
        }

        $inactive = [];

        foreach (array_keys($this->installedPlugins()) as $file) {
            $file = (string) $file;

            if (! is_plugin_active($file)) {
                $inactive[] = $this->pluginSlug($file);
            }
        }

        return $inactive;
    }

    public function unusedThemes(): ?array
    {
        if (is_multisite()) {
            return null;
        }

        $kept = [get_stylesheet(), get_template()];
        // The theme to fall back on, as Site Health chooses it
        $default = wp_get_theme(defined('WP_DEFAULT_THEME') ? WP_DEFAULT_THEME : '');

        if (! $default->exists()) {
            $default = WP_Theme::get_core_default_theme();
        }

        if ($default instanceof WP_Theme) {
            $kept[] = $default->get_stylesheet();
        }

        return array_values(array_diff(array_map(strval(...), array_keys(wp_get_themes())), $kept));
    }

    /**
     * WordPress keeps no date of the last login: what Hornet noted, the open sessions, the application passwords and, when it is
     * on, the journal tell it. The journal also tells when an older account was made an administrator.
     */
    public function administrators(): array
    {
        $users = [];

        foreach (get_users(['capability' => 'manage_options', 'fields' => ['ID', 'user_registered'], 'number' => 500]) as $user) {
            if (is_object($user) && is_numeric($user->ID ?? null)) {
                $users[(int) $user->ID] = Scalar::string($user->user_registered ?? null);
            }
        }

        if (is_multisite()) {
            foreach (get_super_admins() as $login) {
                $user = get_user_by('login', $login);

                if ($user !== false) {
                    $users[$user->ID] = (string) $user->user_registered;
                }
            }
        }

        $journal = $this->settings instanceof Settings && $this->settings->journal->enabled ? new TableJournal : null;
        $promoted = $journal instanceof TableJournal ? $this->promotions($journal) : [];
        $dates = new LoginDates;
        $administrators = [];

        foreach ($users as $id => $registered) {
            $registeredAt = strtotime($registered.' UTC');
            $seen = [$dates->last($id)];

            foreach (WP_Session_Tokens::get_instance($id)->get_all() as $session) {
                $seen[] = is_array($session) && isset($session['login']) ? Scalar::int($session['login']) : null;
            }

            foreach (WP_Application_Passwords::get_user_application_passwords($id) as $password) {
                $seen[] = isset($password['last_used']) ? (int) $password['last_used'] : null;
            }

            if ($journal instanceof TableJournal) {
                $login = $journal->find(new JournalQuery(EventTypes::LOGIN_SUCCESS, null, $id, null, 1))[0] ?? null;
                $seen[] = $login?->at->getTimestamp();
            }

            $seen = array_filter($seen, is_int(...));
            $administrators[] = [
                'user' => $id,
                'since' => max($registeredAt === false ? 0 : $registeredAt, $promoted[$id] ?? 0),
                'last_seen' => $seen === [] ? null : max($seen),
            ];
        }

        return $administrators;
    }

    public function loginsNotedSince(): ?int
    {
        return (new LoginDates)->since();
    }

    /**
     * @return array<int, int> when each account was last given a role that manages the site, in the last 30 days
     */
    private function promotions(TableJournal $journal): array
    {
        $since = $this->now()->sub(new DateInterval('P30D'));
        $promoted = [];
        $events = [
            ...$journal->find(new JournalQuery(EventTypes::USER_ROLE, null, null, $since, JournalQuery::MAX)),
            ...$journal->find(new JournalQuery(EventTypes::SUPER_ADMIN_GRANTED, null, null, $since, JournalQuery::MAX)),
        ];

        foreach ($events as $event) {
            $role = (string) ($event->details['to'] ?? $event->details['added'] ?? '');
            $manages = $event->type === EventTypes::SUPER_ADMIN_GRANTED || ($role !== '' && get_role($role)?->has_cap('manage_options') === true);

            if ($manages && preg_match('/^user:(\d+)$/D', $event->subject, $match) === 1) {
                $promoted[(int) $match[1]] = max($promoted[(int) $match[1]] ?? 0, $event->at->getTimestamp());
            }
        }

        return $promoted;
    }

    public function lastScan(): ?ScanSnapshot
    {
        return (new OptionScanStore)->last();
    }

    /**
     * @return array<array<mixed>> by file (a file name ends in `.php`: the key is text), as `get_plugins()` gives them
     */
    private function installedPlugins(): array
    {
        if (! function_exists('get_plugins') || ! function_exists('is_plugin_active')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        return get_plugins();
    }

    /**
     * The accounts of a list of ids. What is not a number names no account: it is not read as the account 0.
     *
     * @param  array<mixed>  $ids
     * @return list<int>
     */
    private static function numbers(array $ids): array
    {
        return array_values(array_map(intval(...), array_filter($ids, is_numeric(...))));
    }

    private function pluginSlug(string $file): string
    {
        $folder = dirname($file);

        return $folder === '.' ? basename($file, '.php') : $folder;
    }

    /**
     * Reads what WordPress stored. An update that was applied since (by Composer, say) is still listed there: it counts only when
     * the version offered is newer than the one installed.
     *
     * @param  array<string, array{slug: string, version: string}>  $installed  by the key the stored list uses
     * @return array{checked: int, waiting: list<array{slug: string, installed: string, available: string}>, unfollowed: int}|null
     */
    private function updates(string $transient, array $installed): ?array
    {
        $stored = get_site_transient($transient);

        if (! is_object($stored) || ! is_int($stored->last_checked ?? null)) {
            return null;
        }

        $response = is_array($stored->response ?? null) ? $stored->response : [];
        $current = is_array($stored->no_update ?? null) ? $stored->no_update : [];
        $waiting = [];
        $unfollowed = 0;

        foreach ($installed as $key => $one) {
            // An object for a plugin, an array for a theme
            $offer = isset($response[$key]) ? (array) $response[$key] : null;
            $available = $offer['new_version'] ?? null;

            if (is_string($available) && version_compare($one['version'], $available, '<')) {
                $waiting[] = ['slug' => $one['slug'], 'installed' => $one['version'], 'available' => $available];
            } elseif ($offer === null && ! isset($current[$key])) {
                $unfollowed++;
            }
        }

        return ['checked' => $stored->last_checked, 'waiting' => $waiting, 'unfollowed' => $unfollowed];
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

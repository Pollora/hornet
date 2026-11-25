<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Journal;

use Closure;
use DateInterval;
use Pollora\Hornet\Application\Journal\Decision;
use Pollora\Hornet\Application\Journal\FloodGuard;
use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Journal\EventTypes;
use Pollora\Hornet\Domain\Journal\IpAnonymizer;
use Pollora\Hornet\Domain\Journal\Journal;
use Pollora\Hornet\Infrastructure\WordPress\HookRegistrar;
use Pollora\Hornet\Infrastructure\WordPress\Module;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStore;
use Throwable;
use WP_Error;
use WP_User;

/**
 * Listens to WordPress and writes what matters to the journal: who logged in and who failed to, who was created, deleted or given a role,
 * what was installed, updated, activated or removed, which sensitive options changed, which application passwords were made.
 *
 * It records what happened and who did it, and never what a person typed: not a password, not the name that was typed on a login that
 * matches nobody (it may be a password in the wrong field), not the e-mail address of the site.
 */
final class JournalModule implements Module
{
    /** Whether an account is being created: WordPress gives it its role before it says it exists. */
    private bool $creating = false;

    /**
     * Roles added or removed, waiting to be written: `set_role()` removes the old role and adds the new one before it says it changed the role, and that is one change, not three.
     *
     * @var list<array{string, string, array<string, string>}>
     */
    private array $pending = [];

    public const string PURGE_HOOK = 'pollora/hornet/purge_journal';

    /** The options whose change is a change of who can get in, or of where the site is. */
    private const array OPTIONS = ['siteurl', 'home', 'admin_email', 'users_can_register', 'default_role'];

    /** Of those, the ones whose new value is no one's secret and no one's address. */
    private const array SHOWN = ['siteurl', 'home', 'users_can_register', 'default_role'];

    /**
     * @param  Closure(): string  $address  the address of the visitor, as it is
     */
    public function __construct(
        private readonly JournalRecorder $recorder,
        private readonly Journal $journal,
        private readonly Clock $clock,
        private readonly int $retentionDays,
        private readonly FloodGuard $guard,
        private readonly Closure $address,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        // The table first: an event that comes before it would be lost
        $hooks->addAction('init', JournalSchema::ensure(...), 0);
        // The table goes with the site that is deleted from a network
        $hooks->addFilter('wpmu_drop_tables', $this->tablesOfDeletedSite(...), 10, 2);
        $hooks->addAction('init', $this->plan(...));
        $hooks->addAction(self::PURGE_HOOK, $this->purge(...));

        $hooks->addAction('wp_login', $this->loggedIn(...), 10, 2);
        $hooks->addAction('wp_login_failed', $this->loginFailed(...), 10, 2);
        $hooks->addAction('after_password_reset', $this->passwordReset(...), 10, 1);

        $hooks->addFilter('wp_pre_insert_user_data', $this->creating(...), 10, 2);
        $hooks->addAction('user_register', $this->userCreated(...), 10, 1);
        $hooks->addAction('delete_user', $this->userDeleted(...), 10, 1);
        $hooks->addAction('set_user_role', $this->roleSet(...), 10, 3);
        // Written when the request ends, once it is known which of them were part of a change of role
        $hooks->addAction('shutdown', $this->flush(...), 0);
        $hooks->addAction('add_user_role', $this->roleAdded(...), 10, 2);
        $hooks->addAction('remove_user_role', $this->roleRemoved(...), 10, 2);
        $hooks->addAction('grant_super_admin', fn (mixed $id) => $this->superAdmin(EventTypes::SUPER_ADMIN_GRANTED, $id), 10, 1);
        $hooks->addAction('revoke_super_admin', fn (mixed $id) => $this->superAdmin(EventTypes::SUPER_ADMIN_REVOKED, $id), 10, 1);
        $hooks->addAction('wp_create_application_password', $this->appPasswordCreated(...), 10, 2);
        $hooks->addAction('wp_delete_application_password', $this->appPasswordDeleted(...), 10, 2);

        $hooks->addAction('activated_plugin', fn (mixed $plugin) => $this->plugin(EventTypes::PLUGIN_ACTIVATED, $plugin), 10, 1);
        $hooks->addAction('deactivated_plugin', fn (mixed $plugin) => $this->plugin(EventTypes::PLUGIN_DEACTIVATED, $plugin), 10, 1);
        $hooks->addAction('deleted_plugin', $this->pluginDeleted(...), 10, 2);
        $hooks->addAction('switch_theme', $this->themeSwitched(...), 10, 3);
        $hooks->addAction('deleted_theme', $this->themeDeleted(...), 10, 2);
        $hooks->addAction('upgrader_process_complete', $this->upgraded(...), 10, 2);

        $hooks->addAction('updated_option', $this->optionChanged(...), 10, 3);
        $hooks->addAction('pollora/hornet/scan_completed', $this->scanCompleted(...), 10, 0);
    }

    /**
     * @param  mixed  $tables  the tables WordPress is about to drop with a site
     * @return array<mixed>
     */
    public function tablesOfDeletedSite(mixed $tables, mixed $siteId = 0): array
    {
        global $wpdb;

        return [...(is_array($tables) ? $tables : []), $wpdb->get_blog_prefix(is_scalar($siteId) ? (int) $siteId : 0).'hornet_journal'];
    }

    /**
     * Puts the purge in the cron, once.
     */
    public function plan(): void
    {
        if (wp_next_scheduled(self::PURGE_HOOK) === false) {
            wp_schedule_event(time() + 3600, 'daily', self::PURGE_HOOK);
        }
    }

    /**
     * Forgets what is older than the retention: a journal that is never emptied is a pile of personal data that nobody decided to keep.
     */
    public function purge(): int
    {
        return $this->journal->purgeBefore($this->clock->now()->sub(new DateInterval('P'.$this->retentionDays.'D')));
    }

    public function loggedIn(mixed $login, mixed $user = null): void
    {
        if ($user instanceof WP_User) {
            $this->recorder->record(EventTypes::LOGIN_SUCCESS, 'user:'.$user->ID, [], $user->ID, $user->user_login);
        }
    }

    /**
     * A failed login: the account it aimed at is the subject, and nobody is the author. The one who typed is not the account holder, and the
     * attempts of an attacker must not show up as the holder's own doings.
     *
     * It is the one event that a visitor can cause as often as they like: the guard keeps it within bounds.
     */
    public function loginFailed(mixed $username, mixed $error = null): void
    {
        $user = is_string($username) && $username !== '' ? (get_user_by('login', $username) ?: get_user_by('email', $username)) : false;
        $why = $error instanceof WP_Error ? $error->get_error_code() : '';
        $subject = $user instanceof WP_User ? 'user:'.$user->ID : 'unknown account';

        match ($this->guard->decide($this->network())) {
            Decision::Write => $this->recorder->record(EventTypes::LOGIN_FAILED, $subject, ['reason' => (string) $why], 0, ''),
            Decision::FloodNetwork => $this->recorder->record(EventTypes::LOGIN_FLOOD, 'network', ['limit' => $this->guard->perNetwork(), 'seconds' => $this->guard->window()], 0, ''),
            Decision::FloodSite => $this->recorder->record(EventTypes::LOGIN_FLOOD, 'site', ['limit' => $this->guard->perHour(), 'seconds' => 3600], 0, ''),
            Decision::Drop => null,
        };
    }

    /**
     * Who is asking, as the journal keeps it: the shortened address.
     */
    private function network(): string
    {
        return IpAnonymizer::truncate(($this->address)());
    }

    public function passwordReset(mixed $user): void
    {
        if ($user instanceof WP_User) {
            $this->recorder->record(EventTypes::PASSWORD_RESET, 'user:'.$user->ID, [], $user->ID, $user->user_login);
        }
    }

    /**
     * Notes that an account is about to be made (not updated): the role it is given first is told by `user.created`, not as a change.
     *
     * @return mixed what it was given, untouched
     */
    public function creating(mixed $data, mixed $update = true): mixed
    {
        $this->creating = $update === false;

        return $data;
    }

    public function userCreated(mixed $id): void
    {
        $this->creating = false;
        $user = is_int($id) ? get_userdata($id) : false;

        $this->recorder->record(EventTypes::USER_CREATED, 'user:'.(is_int($id) ? $id : 0), ['role' => $user instanceof WP_User ? (string) ($user->roles[0] ?? '') : '']);
    }

    public function userDeleted(mixed $id): void
    {
        $this->recorder->record(EventTypes::USER_DELETED, 'user:'.(is_scalar($id) ? (int) $id : 0));
    }

    /**
     * @param  mixed  $oldRoles  the roles before: empty when the user has just been created, which is told by `user.created`
     */
    public function roleSet(mixed $id, mixed $role, mixed $oldRoles = []): void
    {
        $subject = 'user:'.(is_scalar($id) ? (int) $id : 0);
        // The additions and removals that made this change are not told again
        $this->pending = array_values(array_filter($this->pending, static fn (array $one): bool => $one[1] !== $subject));

        if (! is_array($oldRoles) || $oldRoles === []) {
            return;
        }

        $this->recorder->record(EventTypes::USER_ROLE, 'user:'.(is_scalar($id) ? (int) $id : 0), ['from' => implode(',', array_filter($oldRoles, is_string(...))), 'to' => is_string($role) ? $role : '']);
    }

    public function roleAdded(mixed $id, mixed $role): void
    {
        if ($this->creating) {
            return;
        }

        $this->pending[] = [EventTypes::USER_ROLE, 'user:'.(is_scalar($id) ? (int) $id : 0), ['added' => is_string($role) ? $role : '']];
    }

    public function roleRemoved(mixed $id, mixed $role): void
    {
        $this->pending[] = [EventTypes::USER_ROLE, 'user:'.(is_scalar($id) ? (int) $id : 0), ['removed' => is_string($role) ? $role : '']];
    }

    /**
     * Writes what was waiting.
     */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as [$type, $subject, $details]) {
            $this->recorder->record($type, $subject, $details);
        }
    }

    public function superAdmin(string $type, mixed $id): void
    {
        $this->recorder->record($type, 'user:'.(is_scalar($id) ? (int) $id : 0));
    }

    /**
     * The name of the application password is kept; the password never reaches this method.
     *
     * @param  mixed  $item  what WordPress stored: a name, an identifier, dates
     */
    public function appPasswordCreated(mixed $id, mixed $item): void
    {
        $this->recorder->record(EventTypes::APP_PASSWORD_CREATED, 'user:'.(is_scalar($id) ? (int) $id : 0), ['name' => is_array($item) && is_string($item['name'] ?? null) ? $item['name'] : '']);
    }

    public function appPasswordDeleted(mixed $id, mixed $item): void
    {
        $this->recorder->record(EventTypes::APP_PASSWORD_DELETED, 'user:'.(is_scalar($id) ? (int) $id : 0), ['name' => is_array($item) && is_string($item['name'] ?? null) ? $item['name'] : '']);
    }

    public function plugin(string $type, mixed $file): void
    {
        $this->recorder->record($type, 'plugin:'.self::slug($file));
    }

    public function pluginDeleted(mixed $file, mixed $deleted = true): void
    {
        // WordPress fires the hook whether the files could be removed or not
        if ($deleted === true) {
            $this->recorder->record(EventTypes::PLUGIN_DELETED, 'plugin:'.self::slug($file));
        }
    }

    public function themeSwitched(mixed $name, mixed $theme = null, mixed $old = null): void
    {
        $this->recorder->record(EventTypes::THEME_SWITCHED, 'theme:'.($theme instanceof \WP_Theme ? $theme->get_stylesheet() : (is_string($name) ? $name : '')), ['from' => $old instanceof \WP_Theme ? $old->get_stylesheet() : '']);
    }

    public function themeDeleted(mixed $stylesheet, mixed $deleted = true): void
    {
        if ($deleted === true) {
            $this->recorder->record(EventTypes::THEME_DELETED, 'theme:'.(is_string($stylesheet) ? $stylesheet : ''));
        }
    }

    /**
     * What an installer or an updater did: plugins and themes by name, the core by its version.
     *
     * @param  mixed  $extra  what the upgrader says it did: a type, an action, and what it was done to
     */
    public function upgraded(mixed $upgrader, mixed $extra = []): void
    {
        $type = is_array($extra) ? ($extra['type'] ?? null) : null;
        $action = is_array($extra) ? ($extra['action'] ?? null) : null;

        if (! is_array($extra) || ! in_array($action, ['install', 'update'], true)) {
            return;
        }

        if ($type === 'core') {
            $this->recorder->record(EventTypes::CORE_UPDATED, 'core', ['version' => (string) ($GLOBALS['wp_version'] ?? '')]);

            return;
        }

        if ($type === 'plugin') {
            $files = $action === 'update' ? (is_array($extra['plugins'] ?? null) ? $extra['plugins'] : [$extra['plugin'] ?? null]) : [self::installed($upgrader, 'plugin_info')];

            foreach ($files as $file) {
                $this->recorder->record($action === 'update' ? EventTypes::PLUGIN_UPDATED : EventTypes::PLUGIN_INSTALLED, 'plugin:'.self::slug($file));
            }
        }

        if ($type === 'theme') {
            $themes = $action === 'update' ? (is_array($extra['themes'] ?? null) ? $extra['themes'] : [$extra['theme'] ?? null]) : [self::installed($upgrader, 'theme_info')];

            foreach ($themes as $theme) {
                $this->recorder->record($action === 'update' ? EventTypes::THEME_UPDATED : EventTypes::THEME_INSTALLED, 'theme:'.(is_string($theme) ? $theme : ''));
            }
        }
    }

    public function optionChanged(mixed $option, mixed $old, mixed $new): void
    {
        if (! is_string($option) || ! in_array($option, self::OPTIONS, true) || $old === $new) {
            return;
        }

        // The e-mail address of the site is a person's: that it changed is said, not to what
        $this->recorder->record(EventTypes::OPTION_CHANGED, 'option:'.$option, in_array($option, self::SHOWN, true) && is_scalar($new) ? ['to' => is_bool($new) ? (int) $new : (string) $new] : ['changed' => true]);
    }

    public function scanCompleted(): void
    {
        try {
            $snapshot = (new OptionScanStore)->last();
            $findings = $snapshot->findings ?? [];
        } catch (Throwable) {
            $findings = [];
        }

        $failed = count(array_filter($findings, static fn (Finding $finding): bool => $finding->outcome === Outcome::Fail));
        $unknown = count(array_filter($findings, static fn (Finding $finding): bool => $finding->outcome === Outcome::Unknown));

        $this->recorder->record(EventTypes::SCAN_COMPLETED, 'scan', ['failed' => $failed, 'unknown' => $unknown]);
    }

    /**
     * `akismet/akismet.php` is the plugin `akismet`; `hello.php` is `hello`.
     */
    private static function slug(mixed $file): string
    {
        if (! is_string($file) || $file === '') {
            return '';
        }

        return dirname($file) === '.' ? basename($file, '.php') : dirname($file);
    }

    /**
     * What an installer installed, read from the upgrader: the file of a plugin, the folder of a theme.
     */
    private static function installed(mixed $upgrader, string $method): mixed
    {
        if (! is_object($upgrader) || ! method_exists($upgrader, $method)) {
            return null;
        }

        $info = $upgrader->{$method}();

        return $info instanceof \WP_Theme ? $info->get_stylesheet() : $info;
    }
}

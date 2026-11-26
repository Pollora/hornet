<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Journal;

use Closure;
use Pollora\Hornet\Application\Journal\Decision;
use Pollora\Hornet\Application\Journal\FloodGuard;
use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Domain\Journal\EventTypes;
use Pollora\Hornet\Domain\Journal\IpAnonymizer;
use Pollora\Hornet\Infrastructure\WordPress\HookRegistrar;
use Pollora\Hornet\Infrastructure\WordPress\Module;
use WP_Error;
use WP_User;

/**
 * What the official Two-Factor plugin does, told to the journal: a second factor passed or refused, a method turned on or off.
 *
 * Read from the plugin (0.17): it has actions for a success only (`two_factor_user_authenticated`, `two_factor_user_revalidated`);
 * a refused code fires WordPress's `wp_login_failed` with an error of its own; and it has no action when a method is turned on or off,
 * but every way of doing it (profile, REST, WP-CLI) writes one user meta, whose change is read here. Its
 * `two_factor_login_nonce_failed` is not listened to: it is not an attempt at the second factor, and anybody can fire it.
 *
 * Never a code, never a TOTP key: only the account, by number, and the kind of method.
 *
 * @internal
 */
final class TwoFactorJournalModule implements Module
{
    /** Where the plugin keeps the methods of a user (Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY). */
    private const string METHODS = '_two_factor_enabled_providers';

    /** The errors the plugin hands to `wp_login_failed`. */
    private const array ERRORS = ['two_factor_invalid', 'two_factor_too_fast', 'two_factor_provider_missing'];

    /** The plugin's providers, by the name the journal gives them. Anything else is `other`: a stored string is never repeated. */
    private const array NAMES = [
        'Two_Factor_Totp' => 'totp',
        'Two_Factor_Email' => 'email',
        'Two_Factor_Backup_Codes' => 'backup_codes',
        'Two_Factor_Dummy' => 'dummy',
    ];

    /** @var array<int, list<string>> the methods of a user as they were before a write */
    private array $before = [];

    /** @var array<int, true> accounts being deleted: their metadata going away is not somebody turning a method off */
    private array $deleted = [];

    /**
     * @param  FloodGuard  $guard  one of its own scope: see FloodGuard
     * @param  Closure(): string  $address  the address of the visitor, as it is
     */
    public function __construct(
        private readonly JournalRecorder $recorder,
        private readonly FloodGuard $guard,
        private readonly Closure $address,
    ) {}

    /**
     * Whether the login that is being made stops at a second factor, as the plugin itself decides it.
     */
    public static function isPending(WP_User $user): bool
    {
        return is_callable(['Two_Factor_Core', 'is_user_using_two_factor']) && (bool) call_user_func(['Two_Factor_Core', 'is_user_using_two_factor'], $user->ID);
    }

    public static function isSecondFactorError(mixed $error): bool
    {
        return $error instanceof WP_Error && in_array($error->get_error_code(), self::ERRORS, true);
    }

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('two_factor_user_authenticated', fn (mixed $user, mixed $provider = null) => $this->passed($user, $provider, false), 10, 2);
        $hooks->addAction('two_factor_user_revalidated', fn (mixed $user, mixed $provider = null) => $this->passed($user, $provider, true), 10, 2);
        $hooks->addAction('wp_login_failed', $this->failed(...), 10, 2);

        // Before the write, to know what was there; after it, to tell the difference
        $hooks->addAction('add_user_meta', $this->remember(...), 10, 2);
        $hooks->addAction('update_user_meta', fn (mixed $meta, mixed $user, mixed $key = '') => $this->remember($user, $key), 10, 3);
        $hooks->addAction('delete_user_meta', fn (mixed $meta, mixed $user, mixed $key = '') => $this->remember($user, $key), 10, 3);
        $hooks->addAction('added_user_meta', $this->changed(...), 10, 3);
        $hooks->addAction('updated_user_meta', $this->changed(...), 10, 3);
        $hooks->addAction('deleted_user_meta', $this->changed(...), 10, 3);
        $hooks->addAction('delete_user', $this->accountGoes(...), 10, 1);
        $hooks->addAction('wpmu_delete_user', $this->accountGoes(...), 10, 1);
    }

    public function passed(mixed $user, mixed $provider, bool $revalidation): void
    {
        if (! $user instanceof WP_User) {
            return;
        }

        $key = is_object($provider) && method_exists($provider, 'get_key') ? $provider->get_key() : '';
        $details = ['method' => self::name($key)];

        if ($revalidation) {
            $details['revalidation'] = true;
        }

        $this->recorder->record(EventTypes::SECOND_FACTOR_PASSED, 'user:'.$user->ID, $details, $user->ID, $user->user_login);
    }

    /**
     * A second factor that was refused: the password was right, which makes it worth more than a failed login. It has its own flood
     * limit, so that a burst of wrong passwords cannot be used to hide it.
     */
    public function failed(mixed $username, mixed $error = null): void
    {
        if (! $error instanceof WP_Error || ! self::isSecondFactorError($error)) {
            return;
        }

        $user = is_string($username) && $username !== '' ? get_user_by('login', $username) : false;
        $subject = $user instanceof WP_User ? 'user:'.$user->ID : 'unknown account';

        match ($this->guard->decide(IpAnonymizer::truncate(($this->address)()))) {
            Decision::Write => $this->recorder->record(EventTypes::SECOND_FACTOR_FAILED, $subject, ['reason' => (string) $error->get_error_code()], 0, ''),
            Decision::FloodNetwork => $this->recorder->record(EventTypes::LOGIN_FLOOD, 'network', ['of' => 'second_factor', 'limit' => $this->guard->perNetwork(), 'seconds' => $this->guard->window()], 0, ''),
            Decision::FloodSite => $this->recorder->record(EventTypes::LOGIN_FLOOD, 'site', ['of' => 'second_factor', 'limit' => $this->guard->perHour(), 'seconds' => 3600], 0, ''),
            Decision::Drop => null,
        };
    }

    public function remember(mixed $user, mixed $key): void
    {
        if ($key === self::METHODS && is_numeric($user) && (int) $user > 0) {
            $this->before[(int) $user] = self::methods((int) $user);
        }
    }

    public function changed(mixed $meta, mixed $user, mixed $key = ''): void
    {
        if ($key !== self::METHODS || ! is_numeric($user) || (int) $user <= 0) {
            return;
        }

        $id = (int) $user;
        $before = $this->before[$id] ?? [];
        unset($this->before[$id]);

        if (isset($this->deleted[$id])) {
            return;
        }

        $after = self::methods($id);

        foreach (array_diff($after, $before) as $method) {
            $this->recorder->record(EventTypes::SECOND_FACTOR_ENABLED, 'user:'.$id, ['method' => self::name($method)]);
        }

        foreach (array_diff($before, $after) as $method) {
            $this->recorder->record(EventTypes::SECOND_FACTOR_DISABLED, 'user:'.$id, ['method' => self::name($method)]);
        }
    }

    public function accountGoes(mixed $user): void
    {
        if (is_numeric($user)) {
            $this->deleted[(int) $user] = true;
        }
    }

    /**
     * @return list<string>
     */
    private static function methods(int $user): array
    {
        $stored = get_user_meta($user, self::METHODS, true);

        return is_array($stored) ? array_values(array_unique(array_filter($stored, is_string(...)))) : [];
    }

    private static function name(mixed $provider): string
    {
        return is_string($provider) ? (self::NAMES[$provider] ?? 'other') : 'other';
    }
}

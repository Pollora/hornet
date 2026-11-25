<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Journal;

/**
 * What the journal records: a short list, each with a stable name (`login.failed`). The first part is the group a person filters by.
 * A name that is not on the list is not recorded: the journal holds what Hornet decided to keep, and nothing else.
 */
final class EventTypes
{
    public const string LOGIN_SUCCESS = 'login.success';

    public const string LOGIN_FAILED = 'login.failed';

    public const string PASSWORD_RESET = 'login.password_reset';

    public const string USER_CREATED = 'user.created';

    public const string USER_DELETED = 'user.deleted';

    public const string USER_ROLE = 'user.role_changed';

    public const string SUPER_ADMIN_GRANTED = 'user.super_admin_granted';

    public const string SUPER_ADMIN_REVOKED = 'user.super_admin_revoked';

    public const string APP_PASSWORD_CREATED = 'user.app_password_created';

    public const string APP_PASSWORD_DELETED = 'user.app_password_deleted';

    public const string PLUGIN_ACTIVATED = 'plugin.activated';

    public const string PLUGIN_DEACTIVATED = 'plugin.deactivated';

    public const string PLUGIN_DELETED = 'plugin.deleted';

    public const string PLUGIN_INSTALLED = 'plugin.installed';

    public const string PLUGIN_UPDATED = 'plugin.updated';

    public const string THEME_SWITCHED = 'theme.switched';

    public const string THEME_DELETED = 'theme.deleted';

    public const string THEME_INSTALLED = 'theme.installed';

    public const string THEME_UPDATED = 'theme.updated';

    public const string CORE_UPDATED = 'core.updated';

    public const string OPTION_CHANGED = 'option.changed';

    public const string SCAN_COMPLETED = 'hornet.scan_completed';

    public const string FINDING_SET_ASIDE = 'hornet.finding_set_aside';

    public const string FINDING_RESTORED = 'hornet.finding_restored';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::LOGIN_SUCCESS, self::LOGIN_FAILED, self::PASSWORD_RESET,
            self::USER_CREATED, self::USER_DELETED, self::USER_ROLE, self::SUPER_ADMIN_GRANTED, self::SUPER_ADMIN_REVOKED,
            self::APP_PASSWORD_CREATED, self::APP_PASSWORD_DELETED,
            self::PLUGIN_ACTIVATED, self::PLUGIN_DEACTIVATED, self::PLUGIN_DELETED, self::PLUGIN_INSTALLED, self::PLUGIN_UPDATED,
            self::THEME_SWITCHED, self::THEME_DELETED, self::THEME_INSTALLED, self::THEME_UPDATED,
            self::CORE_UPDATED, self::OPTION_CHANGED,
            self::SCAN_COMPLETED, self::FINDING_SET_ASIDE, self::FINDING_RESTORED,
        ];
    }

    public static function exists(string $type): bool
    {
        return in_array($type, self::all(), true);
    }

    /**
     * The groups a person filters by: `login`, `user`, `plugin`, `theme`, `core`, `option`, `hornet`.
     *
     * @return list<string>
     */
    public static function groups(): array
    {
        return array_values(array_unique(array_map(static fn (string $type): string => explode('.', $type)[0], self::all())));
    }
}

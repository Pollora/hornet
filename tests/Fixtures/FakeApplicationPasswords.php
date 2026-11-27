<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

/**
 * The application passwords of each user, as WP_Application_Passwords gives them (without the passwords).
 */
final class FakeApplicationPasswords
{
    /** @var array<int, list<array<string, mixed>>> */
    public static array $passwords = [];

    /**
     * @return list<array<string, mixed>>
     */
    public static function get_user_application_passwords(int $user): array
    {
        return self::$passwords[$user] ?? [];
    }
}

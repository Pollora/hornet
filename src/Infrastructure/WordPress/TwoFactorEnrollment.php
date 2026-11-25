<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

/**
 * Who has set up a second factor, asked of the official Two-Factor plugin.
 *
 * Enrollment is read from the providers the user has enabled (`get_enabled_providers_for_user`), not from
 * `is_user_using_two_factor()`: the latter runs a filter that other plugins use to skip two-factor for trusted addresses,
 * and it would then say that nobody needs to enroll.
 *
 * The plugin is somebody else's code, so it is reached by name and never required.
 */
final class TwoFactorEnrollment
{
    public function available(): bool
    {
        return class_exists('Two_Factor_Core') && is_callable(['Two_Factor_Core', 'get_enabled_providers_for_user']);
    }

    public function enrolled(int $userId): bool
    {
        if (! $this->available()) {
            return false;
        }

        $providers = call_user_func(['Two_Factor_Core', 'get_enabled_providers_for_user'], $userId);

        return is_array($providers) && $providers !== [];
    }
}

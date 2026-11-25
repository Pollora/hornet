<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Application\Password\BreachCheck;
use WP_Error;

/**
 * Refuses a password that is in a known breach, when it is chosen.
 *
 * It sees the plain password in two places only: the profile form (which also creates users from the dashboard) and the
 * password reset form. A password set from the REST API, from WP-CLI or by another plugin is not covered. It is never asked at
 * login, and a service that does not answer lets the password through.
 */
final readonly class PasswordModule implements Module
{
    public function __construct(
        private BreachCheck $breach,
        private int $threshold,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('user_profile_update_errors', $this->onProfile(...), 10, 3);
        $hooks->addAction('validate_password_reset', $this->onReset(...), 10, 2);
    }

    /**
     * @param  object  $user  the user being saved: `user_pass` holds the new password only when one was typed
     */
    public function onProfile(WP_Error $errors, bool $update, object $user): void
    {
        $this->judge($errors, is_string($user->user_pass ?? null) ? $user->user_pass : '');
    }

    public function onReset(WP_Error $errors): void
    {
        $password = $_POST['pass1'] ?? '';

        $this->judge($errors, is_string($password) && $password !== '' ? wp_unslash($password) : '');
    }

    private function judge(WP_Error $errors, string $password): void
    {
        $count = $this->breach->occurrences($password);

        if ($count !== null && $count >= $this->threshold) {
            $errors->add('hornet_breached_password', '<strong>Error:</strong> That password appears in known data breaches. Choose another one.');
        }
    }
}

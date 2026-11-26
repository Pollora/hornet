<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use WP_User;

/**
 * Notes the logins of the administrators, for the audit, and gives that date to the privacy tools of WordPress.
 *
 * @internal
 */
final readonly class LoginDatesModule implements Module
{
    public function __construct(private LoginDates $dates) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('wp_login', $this->loggedIn(...), 10, 2);
        $hooks->addAction('admin_init', $this->dates->start(...));
        $hooks->addAction('admin_init', $this->policy(...));
        $hooks->addFilter('wp_privacy_personal_data_exporters', $this->exporters(...));
        $hooks->addFilter('wp_privacy_personal_data_erasers', $this->erasers(...));
    }

    public function loggedIn(mixed $login, mixed $user = null): void
    {
        if ($user instanceof WP_User && user_can($user, 'manage_options')) {
            $this->dates->note($user->ID);
        }
    }

    /**
     * @return array<mixed>
     */
    public function exporters(mixed $exporters): array
    {
        $exporters = is_array($exporters) ? $exporters : [];
        $exporters['hornet-last-login'] = ['exporter_friendly_name' => 'Last login (Hornet)', 'callback' => $this->export(...)];

        return $exporters;
    }

    /**
     * @return array<mixed>
     */
    public function erasers(mixed $erasers): array
    {
        $erasers = is_array($erasers) ? $erasers : [];
        $erasers['hornet-last-login'] = ['eraser_friendly_name' => 'Last login (Hornet)', 'callback' => $this->erase(...)];

        return $erasers;
    }

    /**
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public function export(string $email): array
    {
        $user = get_user_by('email', $email);
        $last = $user === false ? null : $this->dates->last($user->ID);

        if ($user === false || $last === null) {
            return ['data' => [], 'done' => true];
        }

        return ['data' => [['group_id' => 'hornet-last-login', 'group_label' => 'Last login', 'item_id' => 'hornet-last-login-'.$user->ID, 'data' => [['name' => 'When', 'value' => gmdate('Y-m-d H:i:s', $last).' UTC']]]], 'done' => true];
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erase(string $email): array
    {
        $user = get_user_by('email', $email);

        return ['items_removed' => $user !== false && $this->dates->forget($user->ID), 'items_retained' => false, 'messages' => [], 'done' => true];
    }

    public function policy(): void
    {
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content('Hornet (security)', wp_kses_post('<p>To protect the site, the date of the last login of each administrator is kept on their account, to find the accounts that are not used any more.</p>'));
        }
    }
}

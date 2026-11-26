<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\XmlRpcState;
use Pollora\Hornet\Domain\Scan\ScanSnapshot;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Throwable;
use WP_Application_Passwords;
use WP_REST_Request;

/**
 * What the checks may know, read from a running WordPress. It reads; it writes nothing and asks nothing of the network.
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

        return array_values(array_filter(array_map(intval(...), $ids), static fn (int $id): bool => ! $enrollment->enrolled($id)));
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

        foreach ($ids as $id) {
            foreach (WP_Application_Passwords::get_user_application_passwords((int) $id) as $password) {
                $passwords[] = [
                    'user' => (int) $id,
                    'created' => (int) ($password['created'] ?? 0),
                    'last_used' => isset($password['last_used']) ? (int) $password['last_used'] : null,
                ];
            }
        }

        return $passwords;
    }

    public function displaysPhpErrors(): ?bool
    {
        return (new ErrorDisplayNote)->read();
    }

    public function lastScan(): ?ScanSnapshot
    {
        return (new OptionScanStore)->last();
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

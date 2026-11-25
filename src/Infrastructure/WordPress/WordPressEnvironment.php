<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use DateTimeImmutable;
use DateTimeZone;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\XmlRpcState;
use Throwable;
use WP_REST_Request;

/**
 * What the checks may know, read from a running WordPress. It reads; it writes nothing and asks nothing of the network.
 */
final class WordPressEnvironment implements Environment
{
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
            Component::TwoFactor => class_exists('Two_Factor_Core'),
        };
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

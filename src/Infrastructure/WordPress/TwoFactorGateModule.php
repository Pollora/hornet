<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Closure;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\I18n\Translator;
use Pollora\Hornet\Domain\Scalar;
use Pollora\Hornet\Infrastructure\Config\AccountSettings;

/**
 * Sends the users of some roles to their profile until they have set up a second factor.
 *
 * It is a gate on enrollment, **not** on login. The Two-Factor plugin has a filter that makes a login fail for a user who has
 * no method yet: using it would lock out everybody who has not enrolled. Here a user logs in with the password as before, and
 * every dashboard page but their profile sends them there; the profile, where the plugin lets them set it up, stays open. Until
 * `two_factor.enforce_after` a notice asks instead of a redirect.
 *
 * It never touches Ajax, cron, REST, XML-RPC or the command line, nor the logout. `HORNET_DISABLED` removes it.
 *
 * @internal
 */
final class TwoFactorGateModule implements Module
{
    /** The pages a user can always reach: their profile, where the plugin lets them enroll, and what it posts to. */
    private const array OPEN_PAGES = ['profile.php', 'admin-post.php', 'admin-ajax.php'];

    /**
     * @param  Closure(string): void  $redirect  sends the user away and ends the request
     * @param  Closure(): ?object  $user  the current user; null when nobody is logged in
     */
    public function __construct(
        private readonly AccountSettings $settings,
        private readonly TwoFactorEnrollment $enrollment,
        private readonly Clock $clock,
        private readonly Closure $redirect,
        private readonly Closure $user,
        private readonly ?Translator $translator = null,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('admin_init', $this->gate(...), 1);
        $hooks->addAction('admin_notices', $this->notice(...));
    }

    public function gate(): void
    {
        if (! $this->mustEnroll() || $this->inGrace() || $this->onOpenPage() || $this->notABrowser()) {
            return;
        }

        ($this->redirect)(admin_url('profile.php?hornet_2fa=required').'#two-factor-options');
    }

    public function notice(): void
    {
        if (! $this->mustEnroll() || $this->notABrowser()) {
            return;
        }

        $message = ($this->inGrace()
            ? Message::of('Your role must set up two-factor authentication before %s.', (string) $this->settings->enforceAfter?->format('j F Y'))
            : Message::of('Your role must set up two-factor authentication. Choose a method below to continue.'))->render($this->translator);

        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html($message),
            esc_url(admin_url('profile.php#two-factor-options')),
            esc_html(Message::of('Set it up')->render($this->translator)),
        );
    }

    /**
     * Whether the current user is of a role that must have a second factor, and has none.
     */
    private function mustEnroll(): bool
    {
        $user = ($this->user)();

        if (! is_object($user) || ! $this->enrollment->available()) {
            return false;
        }

        $roles = is_array($user->roles ?? null) ? array_filter($user->roles, is_string(...)) : [];

        return array_intersect($this->settings->requireRoles, $roles) !== [] && ! $this->enrollment->enrolled(Scalar::int($user->ID ?? null));
    }

    private function inGrace(): bool
    {
        return $this->settings->enforceAfter instanceof \DateTimeImmutable && $this->clock->now() < $this->settings->enforceAfter;
    }

    private function onOpenPage(): bool
    {
        global $pagenow;

        return is_string($pagenow) && in_array($pagenow, self::OPEN_PAGES, true);
    }

    private function notABrowser(): bool
    {
        return wp_doing_ajax() || wp_doing_cron() || defined('REST_REQUEST') || defined('XMLRPC_REQUEST') || (defined('WP_CLI') && WP_CLI);
    }
}

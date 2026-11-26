<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * Open registration with a default role that may do more than read hands the site to anybody. It is also how somebody who got in
 * once keeps a way back. The role is judged by what it may do, not by its name.
 *
 * @internal
 */
final readonly class OpenRegistrationCheck implements Check
{
    /** Any of these takes the site over. */
    private const array TAKES_OVER = ['manage_options', 'promote_users', 'edit_users', 'create_users', 'activate_plugins', 'install_plugins', 'edit_plugins', 'edit_themes', 'edit_files', 'update_core'];

    /** Any of these changes what others wrote, or puts any HTML on the site. */
    private const array CHANGES_OTHERS = ['unfiltered_html', 'edit_others_posts', 'delete_others_posts', 'edit_pages', 'publish_pages', 'moderate_comments', 'manage_categories', 'list_users'];

    /** Any of these writes on the site. */
    private const array WRITES = ['edit_posts', 'publish_posts', 'upload_files'];

    public function id(): string
    {
        return 'open-registration';
    }

    public function label(): string
    {
        return Message::mark('Open registration');
    }

    public function run(Environment $environment): Finding
    {
        $registration = $environment->registrationRole();

        if ($registration === null) {
            return Finding::pass($this->id(), Message::of('Visitors cannot give themselves a role on this site.'));
        }

        ['role' => $role, 'capabilities' => $capabilities] = $registration;
        $fix = Message::of('Turn off "Anyone can register" in the general settings, or make Subscriber the default role. Then look at the accounts created while it was open.');
        $may = static fn (array $some): bool => array_intersect($some, $capabilities) !== [];

        return match (true) {
            $may(self::TAKES_OVER) => Finding::fail($this->id(), Severity::Critical, Message::of('Anyone can register with the role "%s", which can take the site over.', $role), [], $fix),
            $may(self::CHANGES_OTHERS) => Finding::fail($this->id(), Severity::High, Message::of('Anyone can register with the role "%s", which can change the content of others.', $role), [], $fix),
            $may(self::WRITES) => Finding::fail($this->id(), Severity::Medium, Message::of('Anyone can register with the role "%s", which can write or upload on the site.', $role), [], $fix),
            default => Finding::pass($this->id(), Message::of('Anyone can register, with the role "%s", which cannot write on the site.', $role)),
        };
    }
}

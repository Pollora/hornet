<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;

/**
 * Having the Two-Factor plugin is not using it: an administrator who has not set up a second factor is as exposed as before.
 *
 * It names the accounts by number, never by login or address, because a finding may be sent to a chat.
 */
final readonly class TwoFactorCoverageCheck implements Check
{
    private const int LISTED = 5;

    public function id(): string
    {
        return 'two-factor-coverage';
    }

    public function label(): string
    {
        return 'Two-factor authentication of administrators';
    }

    public function run(Environment $environment): Finding
    {
        $without = $environment->usersWithoutTwoFactor();

        if ($without === null) {
            return Finding::unknown($this->id(), 'The Two-Factor plugin is not active: whether administrators use a second factor cannot be told.');
        }

        if ($without === []) {
            return Finding::pass($this->id(), 'Every administrator has set up two-factor authentication.');
        }

        $details = array_map(static fn (int $id): string => 'user #'.$id, array_slice($without, 0, self::LISTED));

        if (count($without) > self::LISTED) {
            $details[] = sprintf('and %d more', count($without) - self::LISTED);
        }

        return Finding::fail(
            $this->id(),
            Severity::Medium,
            sprintf('%d administrator%s not set up two-factor authentication.', count($without), count($without) === 1 ? ' has' : 's have'),
            $details,
            "Ask them to set it up in their profile, or turn on Hornet's `two_factor.require_roles` to send them there.",
        );
    }
}

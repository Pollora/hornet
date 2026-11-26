<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use DateInterval;
use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * An application password enters the REST API with the rights of its account and no second factor. One that nobody uses any
 * more is a door that nobody watches. Accounts are named by number; the name of a password and its address are never read.
 *
 * @internal
 */
final readonly class ApplicationPasswordsCheck implements Check
{
    private const string FORGOTTEN_AFTER = 'P90D';

    private const int LISTED = 5;

    public function id(): string
    {
        return 'application-passwords';
    }

    public function label(): string
    {
        return Message::mark('Application passwords');
    }

    public function run(Environment $environment): Finding
    {
        $passwords = $environment->administratorApplicationPasswords();

        if ($passwords === null) {
            return Finding::pass($this->id(), Message::of('Application passwords are turned off.'));
        }

        if ($passwords === []) {
            return Finding::pass($this->id(), Message::of('No administrator has an application password.'));
        }

        $limit = $environment->now()->sub(new DateInterval(self::FORGOTTEN_AFTER))->getTimestamp();
        $forgotten = [];

        foreach ($passwords as $password) {
            if (($password['last_used'] ?? $password['created']) < $limit) {
                $forgotten[$password['user']] = ($forgotten[$password['user']] ?? 0) + 1;
            }
        }

        if ($forgotten === []) {
            return Finding::pass($this->id(), Message::plural('%d application password of an administrator, used or made in the last 90 days.', '%d application passwords of administrators, all used or made in the last 90 days.', count($passwords), count($passwords)));
        }

        $details = [];

        foreach (array_slice($forgotten, 0, self::LISTED, true) as $user => $count) {
            $details[] = Message::of('user #%d: %d', $user, $count);
        }

        if (count($forgotten) > self::LISTED) {
            $details[] = Message::plural('and %d more', 'and %d more', count($forgotten) - self::LISTED, count($forgotten) - self::LISTED);
        }

        $total = array_sum($forgotten);

        return Finding::fail(
            $this->id(),
            Severity::Medium,
            Message::plural('%d application password of an administrator has not been used for 90 days.', '%d application passwords of administrators have not been used for 90 days.', $total, $total),
            $details,
            Message::of('Revoke what is not needed, in the profile of the account: such a password enters the REST API with its rights and no second factor.'),
        );
    }
}

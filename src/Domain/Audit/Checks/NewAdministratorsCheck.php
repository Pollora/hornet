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
 * An administrator nobody expected is the most usual way somebody stays after getting in. A new one is not a fault: it is a
 * question to the owner, which goes away by itself after two weeks. Accounts are named by number.
 */
final readonly class NewAdministratorsCheck implements Check
{
    private const int DAYS = 14;

    private const int LISTED = 5;

    public function id(): string
    {
        return 'new-administrators';
    }

    public function label(): string
    {
        return Message::mark('New administrators');
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->environmentType() !== 'production') {
            return Finding::pass($this->id(), Message::of('Not a production site: not judged.'));
        }

        $administrators = $environment->administrators();
        $limit = $environment->now()->sub(new DateInterval('P'.self::DAYS.'D'))->getTimestamp();
        $new = array_values(array_filter($administrators, static fn (array $one): bool => $one['since'] > $limit));

        if ($new === []) {
            return Finding::pass($this->id(), Message::of('No account became an administrator in the last 14 days.'));
        }

        // On a site that has just been made, there is no older account to tell the new ones from
        if (count($new) === count($administrators)) {
            return Finding::pass($this->id(), Message::of('Every administrator account is recent: there is no older one to compare with.'));
        }

        $details = array_map(static fn (array $one): Message => Message::of('user #%d', $one['user']), array_slice($new, 0, self::LISTED));

        if (count($new) > self::LISTED) {
            $details[] = Message::plural('and %d more', 'and %d more', count($new) - self::LISTED, count($new) - self::LISTED);
        }

        return Finding::fail(
            $this->id(),
            Severity::Low,
            Message::plural('%d account became an administrator in the last 14 days.', '%d accounts became administrators in the last 14 days.', count($new), count($new)),
            $details,
            Message::of('Make sure you know who made each of them. If you do not recognise one: remove it, change every password and the salts, and find how it was made.'),
        );
    }
}

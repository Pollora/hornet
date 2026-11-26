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
 * An administrator account nobody uses is one whose theft nobody notices. WordPress keeps no date of the last login: Hornet notes
 * it, so nothing is called dormant before it has been watching for 90 days. Until then, what is not known is said so.
 */
final readonly class DormantAdministratorsCheck implements Check
{
    private const int DAYS = 90;

    private const int LISTED = 5;

    public function id(): string
    {
        return 'dormant-administrators';
    }

    public function label(): string
    {
        return Message::mark('Dormant administrators');
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->environmentType() !== 'production') {
            return Finding::pass($this->id(), Message::of('Not a production site: not judged.'));
        }

        $limit = $environment->now()->sub(new DateInterval('P'.self::DAYS.'D'))->getTimestamp();
        $noted = $environment->loginsNotedSince();
        $watchedLongEnough = $noted !== null && $noted <= $limit;
        $dormant = [];
        $untold = 0;

        foreach ($environment->administrators() as $one) {
            // A recent account, or one seen lately
            if ($one['since'] > $limit || ($one['last_seen'] !== null && $one['last_seen'] >= $limit)) {
                continue;
            }

            if ($watchedLongEnough) {
                $dormant[] = $one['user'];
            } else {
                $untold++;
            }
        }

        if ($dormant !== []) {
            $details = array_map(static fn (int $user): Message => Message::of('user #%d', $user), array_slice($dormant, 0, self::LISTED));

            if (count($dormant) > self::LISTED) {
                $details[] = Message::plural('and %d more', 'and %d more', count($dormant) - self::LISTED, count($dormant) - self::LISTED);
            }

            return Finding::fail(
                $this->id(),
                Severity::Low,
                Message::plural('%d administrator has not been seen for 90 days.', '%d administrators have not been seen for 90 days.', count($dormant), count($dormant)),
                $details,
                Message::of('Delete the accounts that are not needed any more, or give them a lesser role.'),
            );
        }

        if ($untold > 0) {
            return Finding::unknown($this->id(), $noted === null
                ? Message::of('Logins are not noted yet: they are from the next visit to the dashboard.')
                : Message::of('Logins are noted since %s: whether an administrator is dormant can be told 90 days after that.', gmdate('Y-m-d', $noted)));
        }

        return Finding::pass($this->id(), Message::of('Every administrator is recent or has been seen in the last 90 days.'));
    }
}

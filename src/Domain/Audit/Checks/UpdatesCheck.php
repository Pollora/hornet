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
 * A plugin or a theme that is behind misses fixes. The check reads the list WordPress already stored: it asks nothing of the
 * network, and says "not verified" when that list is missing or old, which is not the same as nothing to update.
 *
 * @internal
 */
final readonly class UpdatesCheck implements Check
{
    private const string TOO_OLD = 'P7D';

    private const int LISTED = 5;

    private function __construct(private bool $themes) {}

    public static function plugins(): self
    {
        return new self(false);
    }

    public static function themes(): self
    {
        return new self(true);
    }

    public function id(): string
    {
        return $this->themes ? 'theme-updates' : 'plugin-updates';
    }

    public function label(): string
    {
        return $this->themes ? Message::mark('Theme updates') : Message::mark('Plugin updates');
    }

    public function run(Environment $environment): Finding
    {
        $updates = $this->themes ? $environment->themeUpdates() : $environment->pluginUpdates();

        if ($updates === null || $updates['checked'] < $environment->now()->sub(new DateInterval(self::TOO_OLD))->getTimestamp()) {
            return Finding::unknown($this->id(), $this->themes
                ? Message::of('WordPress has not looked for theme updates in the last 7 days.')
                : Message::of('WordPress has not looked for plugin updates in the last 7 days.'));
        }

        $notFollowed = [];

        if ($updates['unfollowed'] > 0) {
            $notFollowed[] = $this->themes
                ? Message::plural('%d theme is not followed by WordPress.org: look for its updates yourself.', '%d themes are not followed by WordPress.org: look for their updates yourself.', $updates['unfollowed'], $updates['unfollowed'])
                : Message::plural('%d plugin is not followed by WordPress.org: look for its updates yourself.', '%d plugins are not followed by WordPress.org: look for their updates yourself.', $updates['unfollowed'], $updates['unfollowed']);
        }

        $count = count($updates['waiting']);

        if ($count === 0) {
            return Finding::pass($this->id(), $this->themes ? Message::of('No theme update is waiting.') : Message::of('No plugin update is waiting.'), $notFollowed);
        }

        $details = array_map(
            static fn (array $one): Message => Message::of('%s: %s is installed, %s is available.', $one['slug'], $one['installed'], $one['available']),
            array_slice($updates['waiting'], 0, self::LISTED),
        );

        if ($count > self::LISTED) {
            $details[] = Message::plural('and %d more', 'and %d more', $count - self::LISTED, $count - self::LISTED);
        }

        return Finding::fail(
            $this->id(),
            Severity::Medium,
            $this->themes
                ? Message::plural('%d theme has an update waiting.', '%d themes have an update waiting.', $count, $count)
                : Message::plural('%d plugin has an update waiting.', '%d plugins have an update waiting.', $count, $count),
            [...$details, ...$notFollowed],
            $this->themes
                ? Message::of('Update from the dashboard, or with Composer (composer update wpackagist-theme/<slug>), after reading what changed.')
                : Message::of('Update from the dashboard, or with Composer (composer update wpackagist-plugin/<slug>), after reading what changed.'),
        );
    }
}

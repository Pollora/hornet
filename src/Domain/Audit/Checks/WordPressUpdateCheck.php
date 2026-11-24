<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;

/**
 * WordPress core that is behind misses fixes. The check reads the answer WordPress already stored: it asks nothing of the network.
 */
final readonly class WordPressUpdateCheck implements Check
{
    public function id(): string
    {
        return 'wordpress-version';
    }

    public function label(): string
    {
        return 'WordPress version';
    }

    public function run(Environment $environment): Finding
    {
        $update = $environment->availableCoreUpdate();

        if ($update === null) {
            return Finding::pass($this->id(), sprintf('WordPress %s: no update was found at the last check.', $environment->wordpressVersion()));
        }

        return Finding::fail($this->id(), Severity::Medium, sprintf('WordPress %s is installed, %s is available.', $environment->wordpressVersion(), $update), [], 'Update WordPress core (a Composer project: composer update johnpbloch/wordpress-core).');
    }
}

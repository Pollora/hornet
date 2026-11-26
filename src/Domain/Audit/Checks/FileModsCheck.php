<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * Where code is deployed (Bedrock, Composer), the dashboard has no reason to install or update it.
 *
 * Advice only: DISALLOW_FILE_MODS also stops WordPress's own updates, which a classic site may want.
 */
final readonly class FileModsCheck implements Check
{
    public function id(): string
    {
        return 'file-mods';
    }

    public function label(): string
    {
        return Message::mark('Installing code from the dashboard');
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->constant('DISALLOW_FILE_MODS') === true) {
            return Finding::pass($this->id(), Message::of('Plugins, themes and updates cannot be installed from the dashboard.'));
        }

        if ($environment->environmentType() !== 'production') {
            return Finding::pass($this->id(), Message::of('Not a production site: not judged.'));
        }

        return Finding::fail($this->id(), Severity::Info, Message::of('Plugins and themes can be installed from the dashboard.'), [], Message::of("If the code is deployed by Composer or a pipeline, define('DISALLOW_FILE_MODS', true). It also stops WordPress's own updates."));
    }
}

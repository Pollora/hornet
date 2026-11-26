<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * The theme and plugin editor turns any stolen administrator session into code execution.
 */
final readonly class FileEditorCheck implements Check
{
    public function id(): string
    {
        return 'file-editor';
    }

    public function label(): string
    {
        return Message::mark('Theme and plugin editor');
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->fileEditingBlocked()) {
            return Finding::pass($this->id(), Message::of('The file editor is disabled.'));
        }

        $details = $environment->isActive(Component::Headless) ? [Message::of('pollora/headless is installed: it defines DISALLOW_FILE_EDIT only when its REST security module runs.')] : [];

        return Finding::fail($this->id(), Severity::Medium, Message::of('An administrator can edit PHP files from the dashboard.'), $details, Message::of("Turn on Hornet's `hardening.file_editor`, or define('DISALLOW_FILE_EDIT', true) in wp-config.php."));
    }
}

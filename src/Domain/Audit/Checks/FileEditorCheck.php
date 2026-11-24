<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Component;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;

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
        return 'Theme and plugin editor';
    }

    public function run(Environment $environment): Finding
    {
        $blocked = $environment->constant('DISALLOW_FILE_EDIT') === true || $environment->constant('DISALLOW_FILE_MODS') === true;

        if ($blocked) {
            return Finding::pass($this->id(), 'The file editor is disabled.');
        }

        $details = $environment->isActive(Component::Headless) ? ['pollora/headless is installed: it defines DISALLOW_FILE_EDIT only when its REST security module runs.'] : [];

        return Finding::fail($this->id(), Severity::Medium, 'An administrator can edit PHP files from the dashboard.', $details, "Define('DISALLOW_FILE_EDIT', true) in wp-config.php (Hornet cannot: wp-config.php loads first).");
    }
}

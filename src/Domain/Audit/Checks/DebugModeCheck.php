<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;

/**
 * A production site that shows its PHP errors tells a visitor where its files are and what it runs.
 */
final readonly class DebugModeCheck implements Check
{
    public function id(): string
    {
        return 'debug-mode';
    }

    public function label(): string
    {
        return 'Debug mode';
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->environmentType() !== 'production') {
            return Finding::pass($this->id(), 'Not a production site: debug mode is not judged.');
        }

        $debug = $environment->constant('WP_DEBUG') === true;
        // WP_DEBUG_DISPLAY defaults to true when WP_DEBUG is on
        $display = $debug && $environment->constant('WP_DEBUG_DISPLAY') !== false;
        $log = $environment->constant('WP_DEBUG_LOG');

        if ($display) {
            return Finding::fail($this->id(), Severity::High, 'PHP errors are shown to visitors.', ['WP_DEBUG is on and WP_DEBUG_DISPLAY is not false'], "Set WP_DEBUG to false, or define('WP_DEBUG_DISPLAY', false).");
        }

        if ($debug) {
            return Finding::fail($this->id(), Severity::Medium, 'WP_DEBUG is on in production.', [], "Define('WP_DEBUG', false) in production.");
        }

        if ($log === true) {
            return Finding::fail($this->id(), Severity::Low, 'WP_DEBUG_LOG writes wp-content/debug.log, a public URL unless the server blocks it.', [], 'Set WP_DEBUG_LOG to a path outside the web root, or to false.');
        }

        return Finding::pass($this->id(), 'Debug mode is off.');
    }
}

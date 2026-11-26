<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit\Checks;

use Pollora\Hornet\Domain\Audit\Check;
use Pollora\Hornet\Domain\Audit\Environment;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\LaravelApplication;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\I18n\Message;

/**
 * A production site that shows its PHP errors tells a visitor where its files are and what it runs.
 *
 * @internal
 */
final readonly class DebugModeCheck implements Check
{
    /**
     * @param  LaravelApplication|null  $application  on Pollora, where APP_DEBUG is what turns WP_DEBUG on and is the thing to change
     */
    public function __construct(private ?LaravelApplication $application = null) {}

    public function id(): string
    {
        return 'debug-mode';
    }

    public function label(): string
    {
        return Message::mark('Debug mode');
    }

    public function run(Environment $environment): Finding
    {
        if ($environment->environmentType() !== 'production') {
            return Finding::pass($this->id(), Message::of('Not a production site: debug mode is not judged.'));
        }

        if ($this->application?->debug() === true) {
            return Finding::fail($this->id(), Severity::High, Message::of('APP_DEBUG is on in production: an error page shows the code and the request.'), [], Message::of('Set APP_DEBUG=false in the .env of the production site.'));
        }

        $debug = $environment->constant('WP_DEBUG') === true;
        // WP_DEBUG_DISPLAY defaults to true when WP_DEBUG is on
        $display = $debug && $environment->constant('WP_DEBUG_DISPLAY') !== false;
        $log = $environment->constant('WP_DEBUG_LOG');

        if ($display) {
            return Finding::fail($this->id(), Severity::High, Message::of('PHP errors are shown to visitors.'), [Message::of('WP_DEBUG is on and WP_DEBUG_DISPLAY is not false')], Message::of("Set WP_DEBUG to false, or define('WP_DEBUG_DISPLAY', false)."));
        }

        if ($debug) {
            return Finding::fail($this->id(), Severity::Medium, Message::of('WP_DEBUG is on in production.'), [], Message::of("Define('WP_DEBUG', false) in production."));
        }

        if ($log === true) {
            return Finding::fail($this->id(), Severity::Low, Message::of('WP_DEBUG_LOG writes wp-content/debug.log, a public URL unless the server blocks it.'), [], Message::of('Set WP_DEBUG_LOG to a path outside the web root, or to false.'));
        }

        return Finding::pass($this->id(), Message::of('Debug mode is off.'));
    }
}

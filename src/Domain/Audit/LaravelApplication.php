<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Audit;

/**
 * What a check may know of the Laravel application around WordPress (Pollora). There is none on a classic WordPress or on Bedrock,
 * and the checks that need it are then not part of the audit at all. Every method reads; none gives a secret away.
 *
 * @internal
 */
interface LaravelApplication
{
    /** Whether `app.debug` (APP_DEBUG) is on. */
    public function debug(): bool;

    /** Whether the application key is set. Its value is never read by a check. */
    public function hasKey(): bool;

    /** Whether Debugbar is installed and set to run even on a production site. */
    public function debugbarForced(): bool;

    /** Whether Debugbar is only shown to signed-in users with a capability (pollora/debugbar does that). */
    public function debugbarRestricted(): bool;
}

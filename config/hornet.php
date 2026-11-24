<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Hornet
|--------------------------------------------------------------------------
|
| Security for WordPress. On Pollora these settings live under `pollora.hornet`;
| on a classic WordPress or Bedrock site they are read from the HORNET_CONFIG constant.
| A key that is not listed here is refused: a typo must not leave a protection off.
|
*/

return [
    // Kill switch. `false` attaches nothing. On a classic site, define('HORNET_DISABLED', true) does the same.
    'enabled' => env('HORNET_ENABLED', true),

    // Ids of the checks that do not run (see `wp hornet status`)
    'disabled_checks' => [],

    // Findings you accept, each with the reason why: ['check' => 'file-mods', 'reason' => 'Updates come from the dashboard on this site.']
    'ignore' => [],

    // One Tools > Site Health test per check
    'site_health' => true,
];

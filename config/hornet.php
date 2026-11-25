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

    // Tell someone when the audit finds something new. Nothing is sent until a channel is listed.
    // A secret (a webhook URL, a token, a signing key) is read from the environment: never write it in this file.
    'notifications' => [
        // off, hourly, twicedaily or daily. It rides on WP-Cron: run `wp hornet notify run` from the system cron if WP-Cron is off.
        'schedule' => 'daily',

        // Seconds before the same finding is told again on the same channel
        'cooldown' => 86400,

        // Messages per channel per hour at most; what is over waits for the next run
        'rate_limit' => 20,

        'channels' => [
            // 'ops-mail' => ['type' => 'mail', 'to' => ['ops@example.com'], 'min_severity' => 'medium'],
            // 'slack' => ['type' => 'slack', 'url' => env('HORNET_SLACK_URL'), 'min_severity' => 'high'],
            // 'teams' => ['type' => 'teams', 'url' => env('HORNET_TEAMS_URL')],
            // 'discord' => ['type' => 'discord', 'url' => env('HORNET_DISCORD_URL')],
            // 'telegram' => ['type' => 'telegram', 'token' => env('HORNET_TELEGRAM_TOKEN'), 'chat_id' => env('HORNET_TELEGRAM_CHAT')],
            // 'ntfy' => ['type' => 'ntfy', 'url' => env('HORNET_NTFY_URL'), 'token' => env('HORNET_NTFY_TOKEN')],
            // 'siem' => ['type' => 'webhook', 'url' => env('HORNET_WEBHOOK_URL'), 'secret' => env('HORNET_WEBHOOK_SECRET')],
        ],
    ],
];

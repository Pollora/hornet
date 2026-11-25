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

    // Close what the site does not need. Everything is off until you turn it on; each line says what it does.
    'hardening' => [
        // No XML-RPC method answers (`xmlrpc_enabled` alone leaves pingbacks open), no X-Pingback header, no RSD link.
        // Jetpack and the WordPress mobile app need XML-RPC.
        'xmlrpc' => false,

        // `?author=1` is a 404 for visitors, and the REST user routes, the users sitemap and the oEmbed author are hidden from them.
        'user_enumeration' => false,

        // No generator tag in the head of the page
        'hide_version' => false,

        // Nobody can edit PHP files from the dashboard (what DISALLOW_FILE_EDIT does, which a plugin cannot define)
        'file_editor' => false,
    ],

    // Security headers. Nothing is sent until you ask for it.
    'headers' => [
        // 'content_type_options' => true,                          // X-Content-Type-Options: nosniff
        // 'referrer_policy' => 'strict-origin-when-cross-origin',  // no-referrer, same-origin, strict-origin, strict-origin-when-cross-origin, origin, origin-when-cross-origin
        // 'permissions_policy' => 'camera=(), microphone=(), geolocation=()',
        // HSTS is sent over HTTPS only. A browser keeps it for max_age seconds: a site that cannot serve HTTPS for a year is out for a year.
        // 'hsts' => ['max_age' => 31536000, 'include_subdomains' => false, 'preload' => false],
        // Content-Security-Policy: start in 'report-only' (the browser reports and blocks nothing). The dashboard is left out unless 'admin' => true.
        // 'csp' => ['mode' => 'report-only', 'policy' => "default-src 'self'; img-src 'self' data: https:", 'report_uri' => 'https://example.report-uri.com/r/d/csp/reportOnly', 'admin' => false],
    ],

    // The proxies in front of the site (a load balancer, a CDN). A forwarding header is believed only from these addresses.
    // On Pollora the address comes from Laravel's request, which has its own trusted-proxies setting: leave this empty there.
    'network' => [
        // 'trusted_proxies' => ['10.0.0.0/8'],
        // 'forwarded_header' => 'X-Forwarded-For',   // or CF-Connecting-IP, X-Real-IP
    ],

    // A fallback login throttle, for a site without pollora/portcullis. Off until you turn it on.
    // It never locks an address that many visitors share: behind a proxy, declare it above first.
    // `wp hornet unlock <ip>` and `wp hornet unlock --all` let addresses back in.
    'login' => [
        'throttle' => false,
        // 'max_attempts' => 5,
        // 'window' => 900,
        // 'lockout' => 900,
        // 'allow_ips' => [],
    ],

    // Rules about accounts. All off until you turn them on.
    'passwords' => [
        // Refuse a password that is in a known breach, when it is chosen (profile form and password reset only). It asks
        // haveibeenpwned.com with five characters of the hash. It fails open: a service that is down lets the password through.
        'check_breached' => false,
        // 'breached_threshold' => 1,   // how many appearances make it refused
    ],
    'session' => [
        // 'lifetime' => 28800,           // seconds a login lasts (WordPress: two days)
        // 'remember_lifetime' => 604800, // with "remember me" (WordPress: fourteen days)
    ],
    'two_factor' => [
        // Send these roles to their profile until they have set up a method of the Two-Factor plugin. A gate on enrollment, not on login.
        // 'require_roles' => ['administrator'],
        // 'enforce_after' => '2026-12-15',   // until this date: a notice only
    ],

    // The file scanner (`wp hornet scan`). It runs when you ask: there is nothing to turn on, and nothing is ever deleted or repaired.
    'scanner' => [
        // Core files that are meant to differ from WordPress.org (a patch your project applies), relative to the WordPress root.
        // Each is pinned the first time it is seen: a later change is reported. On Pollora and Bedrock the files that the
        // patches of patches.lock.json change are allowed by themselves; list here what Hornet cannot find out.
        // 'allow_modified' => ['wp-includes/l10n.php'],

        // Files and folders of the content folder that the suspicious-files scan leaves alone (your false positives), relative to wp-content.
        // 'ignore_paths' => ['uploads/known-legacy', 'themes/mine/vendor'],

        // Themes you write for this site (folder names): they change with every deployment, so they are not compared with a first look.
        // 'own_themes' => ['my-theme'],

        // Ask the site itself, from the server, for what it should not give away (.env, .git, backups, debug.log...). On by default; nothing is written.
        // 'probes' => true,

        // Also put one harmless file in the uploads for a moment, to ask whether the server runs PHP there. Writes, then removes. Off until you ask.
        // 'probe_uploads' => false,

        // Scan from WP-Cron: off, hourly, twicedaily or daily. Off until you ask. On a large site, run `wp hornet scan` from the system cron instead.
        // 'schedule' => 'daily',
    ],

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

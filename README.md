# Hornet

Security for WordPress, from Pollora. Hornet audits the configuration of a site, tells what to fix and how, and shows the same findings in **Tools > Site Health**, in **WP-CLI** and, on a Pollora project, in **`pollora:doctor`**.

It runs on a classic WordPress, on Bedrock and on Pollora, with no dependency on Laravel. By default it changes nothing on your site: it reads, and it tells you. Hardening and headers do nothing until you turn each one on.

> **Status: 0.3.** The audit of the configuration, the notifications, the hardening, the security headers, the login throttle and the account rules are in. The file scanner, the vulnerability feed, the administration screen, the activity log and the MCP abilities come next. Nothing here is a firewall.

## What it checks

| Check | Looks at |
| --- | --- |
| `salts` | the eight keys and salts: set, 32 characters or more, not the sample value. It names the constants and never prints a value |
| `debug-mode` | `WP_DEBUG`, `WP_DEBUG_DISPLAY`, `WP_DEBUG_LOG` on a production site; on Pollora, `APP_DEBUG` |
| `php-error-display` | PHP showing its errors although `WP_DEBUG` is off, as the web server last showed it on a dashboard page (not on Pollora, where Laravel turns it off) |
| `laravel-app-key` | **Pollora only**: `APP_KEY` is set (its value is never read) |
| `laravel-debugbar` | **Pollora only**: Debugbar forced on in production (`debugbar.force_allow_enable`) |
| `https` | the site address, and `FORCE_SSL_ADMIN` |
| `file-editor` | whether the theme and plugin editor is refused, whoever refused it (`DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, Hornet's `hardening.file_editor`) |
| `file-mods` | installing code from the dashboard (advice: it also stops WordPress's own updates) |
| `config-permissions` | the permission bits of `wp-config.php` |
| `php-version` | whether the PHP branch still gets security fixes (dates from php.net, read on 2026-11-24) |
| `wordpress-version` | the update WordPress already found (no request is made) |
| `plugin-updates`, `theme-updates` | the updates WordPress already found (no request is made); `unknown` when it has not looked for 7 days. Software WordPress.org does not know is counted, and your own themes (`scanner.own_themes`) are left out |
| `inactive-plugins`, `inactive-themes` | in production, plugins installed and not active, and themes other than the active one, its parent and one default theme (not judged on a network) |
| `xml-rpc` | what XML-RPC answers: open, logins refused only (pingbacks still answer), or closed |
| `user-enumeration` | whether the REST API lists the users to a visitor |
| `admin-account` | a user named `admin` |
| `open-registration` | anyone can register with a default role that may write, change others' content or take the site over (judged by what the role may do) |
| `login-protection` | whether [pollora/portcullis](https://github.com/Pollora/portcullis) is there |
| `two-factor` | whether the official Two-Factor plugin is active |
| `two-factor-coverage` | the administrators who have not set up a second factor, named by number |
| `new-administrators` | in production, accounts that became administrators in the last 14 days (registered, or promoted when the [journal](#the-journal) is on: without it an older account that is promoted is not seen). A question, not a fault: it goes by itself |
| `dormant-administrators` | in production, administrators not seen for 90 days. WordPress keeps no date of the last login: Hornet notes it on the administrator's account (`hornet_last_login`, personal data, given and erased by the privacy tools), and also reads open sessions, application passwords and the journal. `unknown` until it has watched for 90 days. Turning the check off stops the noting |
| `application-passwords` | application passwords of administrators unused for 90 days (only their dates are read) |
| `core-integrity` | what the last [scan](#scan-the-files) found about the WordPress core files (it reads the result: it never scans) |
| `plugin-integrity` | what the last scan found about the files of the plugins |
| `theme-integrity` | what the last scan found about the files of the themes |
| `suspicious-files` | what the last scan found in the content folder (scripts in the uploads, web shells) |
| `database-scripts` | what the last scan found of **disguised JavaScript in the database** (see [Scan the files](#scan-the-files)) |
| `exposed-files` | what the site gave away when the last scan asked it (a `.env`, a repository, a backup, a log) |
| `security-headers` | in production, the headers of the home page as the last scan received them, whoever set them: `nosniff`, a protection against framing, HSTS on HTTPS. And the headers Hornet is set to send that do not arrive. Content-Security-Policy and Referrer-Policy are not judged. Off with `scanner.probes` |

Site Health shows these checks too, except the four on plugins and themes, which it tests itself.

A check reads and never writes, and never asks the network. A scan is another thing: see below. What Hornet does not know it says so (`unknown`): a file hidden by `open_basedir` is not a pass.

Hornet does not redo what Pollora already does. If `pollora/portcullis` protects the login, the check says so instead of warning.

## Install

On a classic WordPress or Bedrock:

```bash
composer require pollora/hornet
```

Copy `stubs/pollora-hornet.php` to `wp-content/mu-plugins/` (`web/app/mu-plugins/` on Bedrock). Composer's autoloader must be loaded before it: Bedrock does it, a classic install can `require` `vendor/autoload.php` from `wp-config.php`.

On a Pollora project nothing else is needed: Laravel discovers the service provider.

## Use

```bash
wp hornet status                      # the open findings and what could not be verified
wp hornet status --all                # every check, passing ones too
wp hornet status --format=json        # for a script or a pipeline
wp hornet status --fail-on=medium     # exit 1 from a medium finding up (default: high; none = never)
wp hornet scan                        # compare the core files with WordPress.org (asks the network)
php artisan pollora:doctor            # Pollora: one "Security (Hornet)" line
```

`wp hornet status` ends with a failing exit code when a finding at or above `--fail-on` is open, so a deployment can stop on it.

## Configure

A classic or Bedrock site, in `wp-config.php` (or a Bedrock environment file), before WordPress loads:

```php
define('HORNET_CONFIG', [
    // Findings you accept, each with the reason why. A reason is required.
    'ignore' => [
        ['check' => 'file-mods', 'reason' => 'Updates come from the dashboard on this site.'],
    ],
    // Checks that do not run at all
    'disabled_checks' => ['two-factor'],
    // One Tools > Site Health test per check
    'site_health' => true,
]);
```

A Pollora project uses **`config/pollora/hornet.php`** (`php artisan vendor:publish --tag=hornet-config`), read as `pollora.hornet`. A `config/hornet.php` is **not** read (Laravel loads it under `hornet`): Hornet says so in Site Health instead of doing nothing in silence.

- A key Hornet does not know is **refused**, and so is a check id that does not exist: a typo must not leave a protection off. The site keeps running, Hornet stays off, and **Site Health shows one critical test with the message**. `wp hornet status` prints it as an error.
- A finding set aside stays visible, with its reason, in the output and in Site Health.
- **Kill switch**: `define('HORNET_DISABLED', true);` attaches nothing. It is the way back if a rule ever locks someone out.
- On a multisite network, `HORNET_CONFIG['sites'][<site id>]` overrides the shared settings for one site.

## Hardening

Closes what a site does not need. **Every line is off until you turn it on.**

```php
define('HORNET_CONFIG', [
    'hardening' => [
        'xmlrpc' => true,            // no XML-RPC method answers, no X-Pingback header, no RSD link
        'user_enumeration' => true,  // ?author=1 is a 404; no REST user routes, users sitemap or oEmbed author for visitors
        'hide_version' => true,      // no generator tag
        'file_editor' => true,       // nobody can edit PHP files from the dashboard
    ],
]);
```

- `xmlrpc` closes all of XML-RPC. `xmlrpc_enabled` alone only refuses the methods that need a login: **pingbacks still answer**, and the audit says so. Jetpack and the WordPress mobile app need XML-RPC.
- `user_enumeration` blocks `?author=N` before WordPress turns it into `/author/<login>/`, and takes the user routes away from visitors only: a logged-in user keeps them.
- `file_editor` does what `DISALLOW_FILE_EDIT` does, which a plugin cannot define (`wp-config.php` loads first).
- Each piece is a filter that is safe to add twice, so it does not matter that `pollora/headless` may do the same.

## Accounts: passwords, sessions, two-factor

Three rules about accounts. **All off until you turn them on.**

```php
define('HORNET_CONFIG', [
    'passwords' => ['check_breached' => true, 'breached_threshold' => 1],
    'session' => ['lifetime' => 28800, 'remember_lifetime' => 604800],   // seconds
    'two_factor' => ['require_roles' => ['administrator'], 'enforce_after' => '2026-12-15'],
]);
```

- **Breached passwords** (opt-in: it is a call to another service). When a password is chosen, Hornet asks [Have I Been Pwned](https://haveibeenpwned.com/API/v3#PwnedPasswords) whether it is in a known breach, **sending only the first five characters of its SHA-1** (k-anonymity), with `Add-Padding` so the size of the answer says nothing. The comparison is made on the site. It only sees the plain password on the **profile form** (which also creates users from the dashboard) and the **password reset form**: a password set from the REST API, WP-CLI or another plugin is not covered. It is never asked at login, and a service that is down or slow lets the password through (4 seconds at most).
- **Session length.** `lifetime` replaces WordPress's two days, `remember_lifetime` its fourteen days for "remember me", between 15 minutes and 14 days (a year for the remembered one).
- **Two-factor enrollment.** Hornet relies on the official [Two-Factor](https://wordpress.org/plugins/two-factor/) plugin and does not rewrite it. `require_roles` sends the users of those roles to their profile until they have set up a method; the profile, where the plugin lets them do it, stays open, and so do Ajax, cron, REST, XML-RPC and the command line. Until `enforce_after` (a date, `YYYY-MM-DD`) they only get a notice. **This is a gate on enrollment, not on login**: the plugin's own filter that "requires" two-factor makes a login fail for anyone who has not enrolled, which would lock out everybody who has not set it up yet. Enrollment is read from the methods a user has enabled, not from `is_user_using_two_factor()`, which other plugins override to skip two-factor from trusted addresses. The audit's `two-factor-coverage` check names the accounts that have none, by number.

## Login throttle

A fallback for a site that does not run [pollora/portcullis](https://github.com/Pollora/portcullis), which already guards the login (secret URL, brute-force lockout, account enumeration) and which Hornet never doubles. **Off until you turn it on.**

```php
define('HORNET_CONFIG', [
    'network' => ['trusted_proxies' => ['10.0.0.0/8']],   // the proxies in front of the site
    'login' => [
        'throttle' => true,
        'max_attempts' => 5,           // failures ...
        'window' => 900,               // ... within this many seconds lock the address ...
        'lockout' => 900,              // ... for this long, doubling each time the same address earns another (up to a day)
        'allow_ips' => ['203.0.113.50'],   // never counted, never locked
    ],
]);
```

- **Per address only, never per user name**: locking a user name would let anyone lock the owner out.
- **It cannot be undone by WordPress.** The lock runs late on `authenticate`, after WordPress's own checks, and turns even a valid user into an error. Failures of application passwords (REST, XML-RPC) count too; a success forgets the failures.
- **It never locks an address that many visitors share.** Behind a proxy or a load balancer that is not in `network.trusted_proxies`, every visitor would have the proxy's address and one attacker could lock everybody out. Hornet then steps aside, writes a note, and the audit raises a **high** finding. Declare the proxy: a forwarding header (`X-Forwarded-For`, `CF-Connecting-IP` or `X-Real-IP`, set with `network.forwarded_header`) is believed **only** when the connection comes from a listed proxy, and is read from the right, skipping the trusted proxies, so a forged left part counts for nothing.
- **Adding a header does not dodge the lock.** A forwarding header from an address that is not a trusted proxy is ignored and the request is counted under the address that opened the connection. If a header arrives that way, the audit says so: if the site is behind a CDN, list it in `network.trusted_proxies`, or visitors behind the same edge address can lock each other out.
- **On Pollora, the address is the one Laravel's request reports**, which already knows the proxies the application trusts: no second list to keep in step.
- Addresses are stored as an HMAC, never in clear. With a persistent object cache (Redis, Memcached: [pollora/object-cache](https://github.com/Pollora/object-cache)) the counters live in the cache; without one they are transients, which are rows of the options table, so a flood of different addresses writes as many rows.

**The way back in.** A lock is never a one-way door:

```bash
wp hornet unlock 203.0.113.7   # let one address back in
wp hornet unlock --all         # let everybody back in
```

The command line is never locked. `define('HORNET_DISABLED', true);` in `wp-config.php` switches Hornet off altogether.

## Security headers

Nothing is sent until you ask for it.

```php
define('HORNET_CONFIG', [
    'headers' => [
        'content_type_options' => true,                         // X-Content-Type-Options: nosniff
        'referrer_policy' => 'strict-origin-when-cross-origin', // only policies that tighten
        'permissions_policy' => 'camera=(), microphone=(), geolocation=()',
        'hsts' => ['max_age' => 31536000, 'include_subdomains' => false, 'preload' => false],
        'csp' => ['mode' => 'report-only', 'policy' => "default-src 'self'", 'report_uri' => 'https://example.report-uri.com/r/d/csp/reportOnly', 'admin' => false],
    ],
]);
```

- **HSTS** is sent over HTTPS only, needs `max_age` of at least 300 seconds, and refuses `preload` unless `include_subdomains` is on and `max_age` is a year or more: a browser keeps the rule for `max_age` seconds, so a site that cannot serve HTTPS for a year is out for a year.
- **Content-Security-Policy** starts in `report-only` (the browser reports what it would block and blocks nothing; set `report_uri` to read the reports). `enforce` is yours to turn on once the reports are quiet. The dashboard is left out unless `admin` is true: the block editor and TinyMCE need inline code that a strict policy refuses. A policy is **added** to the `frame-ancestors 'self'` that WordPress already sends, never put in its place.
- A value with a line break or a control character is refused: it would be how a second header is injected.
- WordPress has one hook per place: `wp_headers` for the public site, `admin_init` and `login_init` for the dashboard and the login screen. On Pollora the public site is a Laravel response, so a middleware sends the headers there, and skips what PHP has already sent. **That middleware is unit-tested against Symfony responses and has never run inside a Pollora application.**

## Notifications

Hornet can tell you when the audit finds something new: a mailbox, a chat, a webhook. Nothing is sent until you list a channel.

| Type | Needs | Notes |
| --- | --- | --- |
| `mail` | `to` (an address or a list) | through `wp_mail()`: whatever mail setup the site has |
| `slack` | `url` (incoming webhook) | text is escaped the way Slack asks, so `<!channel>` is inert |
| `teams` | `url` (a Workflows "When a Teams webhook request is received" trigger) | an Adaptive Card. The old Office 365 connectors were retired in May 2026. A 2xx means the workflow accepted the request, not that Teams posted it |
| `discord` | `url` | one embed; `allowed_mentions` is empty, so nothing is turned into a ping |
| `telegram` | `token`, `chat_id` | HTML mode, escaped |
| `ntfy` | `url` (the topic), optional `token` | plain text, priority by severity; `allow_private` for a self-hosted server |
| `webhook` | `url`, `secret` | JSON, signed; `insecure` allows plain http, `allow_private` a local address |

```php
define('HORNET_CONFIG', [
    'notifications' => [
        'schedule' => 'daily',      // off, hourly, twicedaily, daily
        'cooldown' => 86400,        // seconds before the same finding is told again on a channel
        'rate_limit' => 20,         // messages per channel per hour
        'channels' => [
            'ops-mail' => ['type' => 'mail', 'to' => ['ops@example.com']],
            'slack' => ['type' => 'slack', 'url' => ['env' => 'HORNET_SLACK_URL'], 'min_severity' => 'high'],
            'siem' => ['type' => 'webhook', 'url' => ['env' => 'HORNET_WEBHOOK_URL'], 'secret' => ['constant' => 'HORNET_WEBHOOK_SECRET']],
        ],
    ],
]);
```

- **Secrets stay out of the repository and the database.** A webhook URL, a token and a signing key are written as `['env' => 'NAME']` or `['constant' => 'NAME']`. A plain string is accepted too (a Pollora `config/pollora/hornet.php` calls `env()` itself). A secret prints as `[redacted]` everywhere and an error never repeats an address.
- **Grouped, told once, calm.** A channel gets one message per run with every alert it is due. The same finding is not repeated on a channel before the cooldown ends (a finding that gets worse is new). A channel gets at most `rate_limit` messages an hour; what is over waits for the next run, nothing is lost. Each channel has its own `min_severity` (default `medium`).
- **Persistent.** A message that fails for a reason that can pass (network, 429, 5xx) is tried again after 1 minute, 5 minutes, 30 minutes and 2 hours, honouring the service's `Retry-After`. One that cannot succeed (a revoked token, a deleted webhook: 4xx) is reported and not repeated.
- **Text from a site is data.** Control and invisible characters are removed, mentions (`@everyone`, `<!channel>`) are neutralised, and every message is cut below the limits of the service.
- **Calls only go where you said.** Private and local addresses are refused unless a channel has `allow_private`, and a redirect is never followed.
- **WP-Cron only runs when a page is visited.** On a production site set `DISABLE_WP_CRON` and call `wp cron event run --due-now` (or `wp hornet notify run`) from the system cron.

```bash
wp hornet notify status      # the channels (never their addresses) and the messages waiting
wp hornet notify test slack  # a test message, whatever the severity and cooldown
wp hornet notify run         # audit now and tell the channels, as the schedule does
wp hornet notify retry       # try the waiting messages again now
```

### The webhook contract (version 1)

```
POST <url>
Content-Type: application/json
X-Hornet-Id: <the id of the message: the same on every retry, use it to deduplicate>
X-Hornet-Timestamp: <unix time>
X-Hornet-Signature: sha256=<hex of HMAC-SHA256(secret, "<timestamp>.<raw body>")>

{"version":1,"event":"hornet.alert","id":"…","sent_at":"…","site":{"name":"…","url":"…"},"severity":"high",
 "alerts":[{"key":"…","severity":"…","title":"…","details":["…"],"fix":null}]}
```

Verify it in Node:

```js
import { createHmac, timingSafeEqual } from 'node:crypto';

function verify(rawBody, headers, secret) {
  const timestamp = headers['x-hornet-timestamp'];
  if (Math.abs(Date.now() / 1000 - Number(timestamp)) > 300) return false; // refuse an old message
  const expected = 'sha256=' + createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex');
  const given = headers['x-hornet-signature'] ?? '';
  return given.length === expected.length && timingSafeEqual(Buffer.from(given), Buffer.from(expected));
}
```

## Scan the files

```bash
wp hornet scan                        # compare the core files with WordPress.org
wp hornet scan --format=json          # for a script or a pipeline
wp hornet scan --fail-on=medium       # exit 1 from a medium finding up (default: high)
wp hornet scan --repin                # accept the allowed core files and the plugins' first look as they are now, and walk the content folder again from the start
```

A scan reads the disk, and asks `api.wordpress.org` for the checksums of the release you run (only the version and the locale are sent: not the address of the site). It runs when you ask, from WP-CLI, and never inside a page request. It **never deletes, repairs or quarantines a file**: it tells you, and you decide.

`ignore` and `disabled_checks` apply to the scan as they do to the audit: a finding you set aside (with its reason) does not fail the command, and `disabled_checks => ['core-integrity']` stops the scan from running and from asking WordPress.org.

A scan that could not look does not erase what an earlier one found: if the last scan found a modified file and this one could not reach WordPress.org, the finding stays, with the date and the reason it was not checked again. Only a scan that looked and found nothing clears it.

The result is kept (in one option that WordPress does not load on every page; on a multisite network, for the network, since the files are shared). The `core-integrity` check, Site Health and `pollora:doctor` show it. A clean result older than seven days is a low finding: it says little about the files today.

**Core files.** For each file WordPress.org lists (the content folder is left out), Hornet reports a file that differs, a file that is gone, and a file that WordPress does not ship in `wp-admin`, `wp-includes` or as a script in the root: that is where a backdoor lands. A clean answer means every listed file has the hash WordPress.org published. A release that WordPress.org knows nothing of (a beta, a development build), or an answer that did not come, is `unknown`, never a pass.

**A project that patches the core on purpose.** Pollora patches `wp-includes/l10n.php` (so that Laravel can declare `__()`). The files changed by the patches of `patches.lock.json` are read from the patches themselves (found in the framework or in the `patches` folder, taken only when their SHA-256 is the one in the lock) and allowed to differ. Anything else is listed by hand:

```php
'scanner' => ['allow_modified' => ['wp-includes/some-file.php']],
```

An allowed file is not trusted for good: its hash is pinned the first time it is seen, and a later change is reported (the one file that may differ would otherwise be the best place to hide in). A pin belongs to one release of the core: an update pins again. After you change a patch on purpose, `wp hornet scan --repin` accepts the files as they are.

**Scripts in the database.** What an SQL injection or a stolen account leaves: posts (not revisions nor trash), options (not transients), approved comments and the website of the profiles. A `<script>` alone is **never** reported: embeds, analytics and header plugins put it there on purpose. What is reported is code that hides what it does (runs or writes decoded code, redirects to a hidden address, long lists of character codes, obfuscated names, a script from a bare IP address, a hidden frame outside `<noscript>`), and code where none belongs (the name or address of the site: critical; a comment; a profile). A finding names the entry (`post #12`, `option widget_text`) and never repeats its content. An entry you know is left alone with `scanner.ignore_content` (`['post:12', 'option:widget_text']`).

It is built for large databases: the tables are read in ranges of primary keys, the database filters, and only the few matching texts reach PHP. Measured with one million posts: MySQL 8 (2.6 GB) a full pass in 20 s, no query over 105 ms; MariaDB 11 (1.8 GB) 21 s, no query over 127 ms; 6 MB more memory. A table the database refuses to read is said `unknown`, never clean. With WP-Cron's time budget it stops, keeps its place and goes on at the next run. Not read: post meta (page builders keep scripts there on purpose), the other sites of a network, and what an injection hides outside the first and last 128 KB of a very long text. It is not a malware scanner with signatures: a script written plainly, loaded from an ordinary domain, is not told from a legitimate one.

**Plugins.** Each plugin that has a folder of its own (active or not) is compared with the list that `downloads.wordpress.org` publishes for its slug and version: SHA-256 of every file, one request per plugin, and only the slug and the version are sent. A file that differs, a file that is gone and a **script that is not in the list** are reported; other unlisted files (logs, caches, uploads) and the `node_modules` and `.git` folders are not, so that the one that matters is not lost in noise.

A plugin WordPress.org has no list for (a premium plugin, one installed from elsewhere, a version it never had) has no reference. Its files are hashed **the first time they are seen** and compared afterwards; a plugin that was bad from the very first look cannot be told from a good one, and the finding says "first look kept" rather than "ok". An update is a new version, so a new first look. After you reinstall a plugin on purpose, `wp hornet scan --repin` takes the plugins as they are now. The first looks are kept in one option without autoload (`hornet_baseline`); a plugin of more than 5000 files is not kept. The scan stops asking after four minutes and names the plugins it did not reach. A plugin that is a single file (`hello.php`) is not looked at.

**Suspicious files.** The content folder (uploads, themes, mu-plugins, drop-ins; the plugins are left to the plugin scan, and `node_modules`, `.git` and cache folders are skipped) is walked for:

| Signal | Severity |
| --- | --- |
| a PHP file in the uploads folder (WordPress's "Silence is golden" `index.php` files are not) | high |
| an `.htaccess` that makes files run as PHP, or a `.user.ini` that runs a file before or after every script (high in the uploads, medium elsewhere) | high / medium |
| a PHP file that reads like a web shell: `eval`, commands, decoders, backticks, `preg_replace` with `/e`, a very long blob, and above all request input reaching one of them (score 7 or more) | high |
| the same with a lower score (5 or 6) | medium |
| a name with a PHP extension in the middle (`shell.php.jpg`), which some servers run; a hidden PHP file (`.cache.php`) | medium |

The rules are Hornet's own, read from the PHP tokens of the file (`token_get_all`: nothing to install, nothing run): the rule sets that exist are under licences a MIT package cannot take. No rule is enough alone, because legitimate code uses `eval`, `base64_decode` or a variable function now and then; points add up. Of a file larger than 512 KB, the first 512 KB are scored. Expect some false positives (a page builder, an obfuscated but honest plugin): `scanner.ignore_paths` lists files and folders, relative to the content folder, to leave alone. It is **not** a replacement for a malware scanner with a signature database: a payload that avoids these rules is not seen.

**Schedule.** Off until you ask: `'scanner' => ['schedule' => 'daily']` (or `hourly`, `twicedaily`) puts the scan in WP-Cron. WP-Cron runs in a web request, so the scan keeps to its time limit: the whole run is held to the PHP `max_execution_time` less a margin, and never more than 45 seconds; the plugin scan gets half of it (`Not checked: … (out of time)` names what it did not reach) and the walk of the content folder a quarter. The walk is the one that goes on: it stops, keeps its cursor and what it found (a failure found earlier stays visible meanwhile), and a minute later a run of **that walk only** (not the core, not the plugins) continues from there. Each task is saved when it is done, so a run that is cut loses the task it was in and not the others. Two runs never overlap: the second steps aside. On a network the main site runs the scan, once: the files are shared.

On a large site (many plugins, a large uploads folder) use `wp hornet scan` from the system cron instead, with `DISABLE_WP_CRON`: there is no time limit there, and the walk is made in one go. The audit, which tells the channels what is new, reads the result of the scan at its own turn.

**Shells.** Besides the usual signs, the rules know a function named by the request (`$_POST['a']($_POST['b'])`), a callback handed the request (`call_user_func($_GET['f'])`, `array_map('assert', $_POST)`, `assert($_POST['x'])`) and an `include` of a file the request names: each is 7 points, high on its own. The "Silence is golden" exemption is decided on the PHP tokens, so a comment that is followed by code on the same line is not silence.

**Themes.** WordPress.org publishes no checksums for themes, so every theme (active or not) gets the plugins' "first look": its files are hashed once, and a change, a missing file or a script that is not there any more is reported afterwards. The first looks have their own option (`hornet_baseline_themes`). A theme you write yourself changes with every deployment: list its folder name in `scanner.own_themes` and it is left out. `--repin` takes the themes as they are now.

**What the site gives away.** The scan asks the site itself, from the server, for about thirty fixed addresses: `.env` files, `.git`, copies of `wp-config.php` and of the database, archives, `.htpasswd`, `phpinfo.php`, PHP error logs, `wp-content/debug.log`, a listing of the uploads and of the plugins. An address counts only when it answers 200 **and** the start of the answer looks like the file (a site that sends its home page for every unknown address gives nothing away). The finding names the address and what it is, never what it holds: a password that was reachable is not repeated in the report. Nothing is written for these questions. A server that does not answer its own address (DNS, a firewall, a certificate it does not trust) gives `unknown`. `scanner.probes => false` turns them off.

**Does the server run PHP in the uploads?** The most useful question writes: with `scanner.probe_uploads => true` the scan puts one small file (`hornet-probe-<random>.php`, which prints a random token that is not in its source) in the uploads, asks for it over HTTP, and removes it at once. If the answer is the token, the server runs PHP that sits in the uploads (high): a file that gets there is run. A server that refuses, or sends the file as text, is fine. It is the only place where the scan writes; it removes only a file of that exact name that holds its own mark (a file left by a run that died is swept at the next one). Off until you ask.

What it cannot do, so you know: a core that was changed **before** the first scan with a file you allowed is pinned as it is; a compromised WordPress.org answer would be believed (the request is over HTTPS and nothing is cached); the probes ask a fixed list: a backup with another name is not found, and a file protected by the web server's own rules is not the site's to say.

## The screen: Tools > Security

One entry in the **Tools** menu (never a menu of its own), for the administrators (`manage_options`). On a network it is in the network administration, under **Settings**, for the super administrators only: the files and the scan are shared by every site, and a site administrator must not read what is true of the others. It is built with the public components of the block editor (`@wordpress/components`, the ones WordPress ships) and looks like the rest of the dashboard. It runs on WordPress 6.9 and later, and was tried in a browser on 6.9 and on 7.1.

- **Overview**: a **security score** (out of 100, with a grade from A to F), the findings to fix first with the points each one costs, how many are open, not verified, set aside and passing, and the scan: one cell per task, lit as the scan goes; **Scan now**.

  The score is a summary, not a promise: each open finding costs points by severity (critical 40, high 20, medium 8, low 3, info 1); the worst open one holds the grade back (a critical finding reads E at best, a high one C); a high or critical finding that was set aside costs nothing but keeps the site from an A; what could not be verified costs nothing and the score is then said to be provisional. Agents get the same score and grade from `hornet/get-status`.
- **Findings**: every check, the open ones first and the worst first, with a search and filters by status and severity; a click on a check says what was found and what to do. Text that comes from the site (a file name, a plugin slug) is shown as text, never as markup. **Set aside…** hides a finding that is open on a reason you write (8 to 300 characters): the reason is shown with your account name and the date, kept in the `hornet_set_aside` option, and said in the journal (`hornet.finding_set_aside`, `hornet.finding_restored`). **Bring it back** undoes it. What the settings file sets aside (`ignore`) wins: it keeps its reason and the screen cannot change it. Only a finding that is open can be set aside; what passes has nothing to hide.
- **Settings**: what is on and what is off, and why (`hardening.xmlrpc`…). **Read only**: the settings are written in the file, and a protection that a screen could switch off is one that a stolen session could switch off. Never a secret, never an address: a channel is named by its name and its kind.
- **Notifications**: the channels, with a button to send a test message.
- **Journal**: the events of [the journal](#the-journal), the newest first, by page, filtered by kind and by where the request came from. When the journal is off, the tab says how to turn it on.

**Scan now** does not wait for the scan: it asks for it, and WP-Cron runs it in the background (the screen shows which task it is at, and says when it is done). With `DISABLE_WP_CRON` (Pollora sets it), the server must run the scheduled tasks (`* * * * * curl -s https://example.com/wp-cron.php`, or `wp cron event run --due-now`). When the scan still has not started after 75 seconds, the screen says why and gives that line. Two scans never run at once.

**Languages.** The screen, what the server writes in it (the checks, what each finding says and what to do, the scan tasks, the settings rows), the menu and page title, Site Health and the messages a user meets (login lock, breached password, two-factor notice) are translated in French, Spanish, German, Brazilian Portuguese, Italian, Dutch, Japanese, Simplified Chinese, Korean, Russian, Polish and Turkish (`languages/`); English otherwise, each person in their own language. The findings of a notification are written in the language of the site. These translations are **machine-made and not yet reviewed by native speakers**: corrections are welcome. **Left in English on purpose:** WP-CLI (scripts read it), what an AI agent is given (abilities), the fixed wording around a notification, the journal's event names, `pollora:doctor`.

What a finding says is stored in English with what is needed to translate it, and translated when it is shown: a scan run by the cron is read in each person's language, and a change of language never makes old news new. To change a translation, edit its `.po` and run `npm run i18n` (it writes what WordPress reads: JSON for the screen, `.l10n.php` for the server); `npm run i18n:check`, which the workflow runs, fails on a missing string, a placeholder that is lost or added, or a stale file. In the code, a sentence is `Message::of('…', $value)` with a literal template; anything that comes from the site goes in the values.

`'admin' => false` removes the screen and its routes. Its routes are `hornet/v1/status`, `hornet/v1/scan`, `hornet/v1/notify/test`, `hornet/v1/journal` and `hornet/v1/findings/{check}/ignore` (POST to set aside, DELETE to bring back), for the people who may see the screen and with the nonce of their session. They are not listed in `/wp-json/`: a security package does not announce itself to whoever asks.

**The compiled screen is part of the package.** `assets/admin` is built from `admin/src` (`npm ci && npm run build`) and kept in git, so that nothing runs npm on a site. A workflow fails when what is tracked is not what the source builds to. The package serves the files itself, to the people who may see the screen: its folder is outside the web root on Bedrock and on Pollora. About 24 KB of JavaScript and 64 KB of CSS (the Space Grotesk font is inside it, under the SIL Open Font License, see `licenses/`: no request to a font service) are loaded, on that screen only, and it needs a browser that the block editor supports. The screen uses no private API of WordPress (the table is plain components, not DataViews, which relies on them): an update of WordPress should not break it. If a script it needs is missing, it says so on the page, instead of staying empty.

## Rules for the web server

Some protections cannot be applied by a plugin, because the request never reaches PHP. `wp hornet server-config nginx`, `apache` (virtual host) and `htaccess` **print** rules for them; they write nothing, anywhere: a server configuration is yours, a mistake in it takes the site down, and a plugin that edits it can be made to edit it.

```bash
wp hornet server-config nginx > hornet.conf          # read it, include it in the server block, nginx -t, reload
wp hornet server-config apache --headers             # Apache 2.4 virtual host; --headers adds nosniff, referrer and permissions policies
wp hornet server-config htaccess                     # the same for an .htaccess (mod_rewrite, AllowOverride FileInfo), above "# BEGIN WordPress"
wp hornet server-config nginx --uploads=/app/uploads # Bedrock or Pollora: the URL path of the uploads folder (by default, this site's)
```

- **No PHP from the uploads folder**, nor a double extension (`shell.php.jpg`): the usual way a webshell is planted. The scanner's probe `exposed-files` tells you when that is not the case.
- **No dotfile** (`.env`, `.git`, `.htaccess`), except `.well-known`.
- **No file that should never be public**: `wp-config.php`, `composer.json` and `.lock`, `package.json`, `auth.json`, dumps, backups, logs.
- With `--headers`, the headers that never change. Do not turn them on when Hornet's own `headers` settings are on: they would be set twice.

The folder is checked before it goes into a configuration file (a path of letters, digits, dots, dashes and underscores). On nginx, put the rules before the `location ~ \.php$` block. The three sets were run against a real nginx 1.27 and Apache 2.4: a PHP file in uploads, `a.php.jpg`, `.env`, `.git/config`, `.vscode/sftp.json` and `composer.json` answer 403; an image, `index.html` and `.well-known/x` answer 200. Do not paste the `apache` set into an `.htaccess`: Apache answers 500 to every request.

## Abilities: what an AI agent may ask

With `abilities.enabled`, the audit is published as **abilities** of the WordPress Abilities API (WordPress 6.9 and later), through [`pollora/abilities`](https://github.com/Pollora/abilities). The MCP Adapter plugin turns abilities into MCP tools, and the abilities say they may be offered to it (`meta.mcp.public`). Hornet runs no MCP server of its own, and sends nothing anywhere: an agent asks, the site answers.

| Ability | Does | Needs |
| --- | --- | --- |
| `hornet/get-status` | counts of open, not verified, set aside, passing findings; age of the last scan; scan running | `abilities.enabled` |
| `hornet/list-findings` | the findings, the ones that need attention first; filter by status and severity; 50 at most, by page | " |
| `hornet/get-remediation` | what to do about one finding | " |
| `hornet/read-journal` | the journal, newest first, by kind and source; 50 at most; says so when the journal is off | " |
| `hornet/run-scan` | asks for a scan (it runs in the background; one at a time) | `abilities.writes` |
| `hornet/set-aside-finding` | hides an open finding, on a written reason | `abilities.writes` |
| `hornet/restore-finding` | shows it again | `abilities.writes` |

```php
'abilities' => [
    'enabled' => true,  // the agent reads
    'writes'  => false, // and, if you say so, starts a scan and sets findings aside
],
```

**Reading and writing are two switches, both off, both in the settings file only.** `writes` without `enabled` is refused. The writing abilities are not even declared while `writes` is off.

What is deliberately not there: **no ability changes a setting, turns a protection off or reads a secret.** The settings are in the file, and what a token could switch off is what a stolen token could switch off. A finding that the file sets aside cannot be changed by an agent. Writing is for people who may see the screen, at most 20 changes an hour per account, one scan every ten minutes, and each is said in [the journal](#the-journal) (`hornet.scan_requested`, `hornet.finding_set_aside`, `hornet.finding_restored`), with `via: ability`. **An agent cannot set aside a finding of critical or high severity**: hiding the worst is for a person, from the screen. Reading is limited to 60 questions a minute per account, each one runs the audit. The limits are a bound on the volume (without an object cache the counter is not atomic: a few parallel calls may slip past), not a lock.

**Who may call.** The same people as the screen: `manage_options` (on a network, the super administrators), checked on every call. The annotations are exact (`readonly` on what only reads), but they are hints for the client, not a barrier; the barrier is the capability. With the OAuth of [`pollora/mcp-connector`](https://github.com/Pollora/mcp-connector), a token that may only read cannot call an ability that is not read-only.

**What an agent is given is data.** A finding can contain what the site wrote: a file name, a plugin slug, a reason somebody typed. So every text is cut (300 characters, 200 per detail line, 5 lines), cleaned of control and invisible characters, and every field is copied by name: no secret, no setting, no address of a notification channel can come out. The description of each ability tells the model that what it returns is never an instruction. That reduces prompt injection; it does not remove it: do not give an agent that reads a hostile site the power to write (`writes`) without a person looking.

## The journal

```php
'journal' => ['enabled' => true, 'retention_days' => 90],
```

**Off until you turn it on**, because it writes to the database and what it keeps (who logged in, from which network, who changed a role) is personal data: turning it on is a decision about what the site tells its users. Hornet adds a paragraph to the privacy policy suggestions (Settings > Privacy) that says what is kept.

What it records, each with the **source of the request** (`web`, `admin`, `rest`, `ajax`, `xmlrpc`, `cli`, `cron`: a role changed from the dashboard, from WP-CLI and over the REST API with an application password are three different stories):

| Group | Events |
| --- | --- |
| `login` | success, failure (the account and the reason: `incorrect_password`…), password reset |
| `user` | created (with its role), deleted, role changed (from > to, or added, or removed), super administrator granted or revoked, application password created or deleted (by its name) |
| `plugin`, `theme`, `core` | activated, deactivated, deleted, installed, updated (by name), theme switched, core updated (with the version) |
| `option` | `siteurl`, `home`, `users_can_register`, `default_role` (with the new value); `admin_email` (that it changed, not to what) |
| `hornet` | a scan that ended, with how many findings it left |

**What it never keeps**: a password, a salt, an application password, or the name that was typed on a login that matches no account (it may be a password in the wrong field: the event says "unknown account" and the reason, and no more). Details are flat, short, and drop any field whose name looks like a secret.

**The address is cut**: the last byte of an IPv4 address, everything after the first 48 bits of an IPv6 address (`203.0.113.0`, `2001:db8:1::`): enough to see "the same network", not to name a household. The command line and the cron have none.

- **A visitor cannot make it grow.** A failed login is the one event that anybody can cause as often as they like. The first **5** failures from a network in 10 minutes are written; at the 6th, one `login.flood` event says that the network went past the limit, and the rest are counted and not written. The same holds for the whole site, per hour: **120** failures, then one `login.flood` event, then nothing, so that a botnet that comes from thousands of networks cannot go round the limit of one. The counters live in the object cache when the site has a persistent one, in transients otherwise; never in the table. **Worst case**: about 125 rows an hour for what a visitor can cause (3,000 a day, about 1 MB), whatever is sent; what administrators do is not limited, and is small. A failed login is recorded with nobody as its author and the account it aimed at as its subject: the attempts of an attacker are not the account holder's doings.
- **Where**: one table per site (`{prefix}hornet_journal`), made with `dbDelta` and a version in an option; nothing in the posts or their meta. A table that cannot be written never stops a login or an update: the failure is counted, not thrown.
- **Retention**: `retention_days` (90 by default, 1 to 3650); a daily WP-Cron event (`pollora/hornet/purge_journal`) forgets what is older. `wp hornet journal purge` does it now.
- **Privacy tools**: the journal is an exporter (Tools > Export Personal Data: the events that name a person, as the one who did it or the one it was done to) and an eraser (Tools > Erase Personal Data). Erasing **keeps the events** (a security journal that forgets what happened is no journal) and takes the person out of them: their number, their login and the address are blanked.
- **WP-CLI**: `wp hornet journal list [--type=login.|user.|plugin.|theme.|core.|option.|hornet.|<a type>] [--source=] [--user=] [--days=] [--limit=] [--format=]`, `wp hornet journal purge`, and `wp hornet journal drop --yes` to remove the table and its option (what an uninstall should do).
- **Multisite**: the journal is per site, and the table goes with the site when it is deleted. Accounts belong to the network, but the **eraser works on the table of the site it is run from**: erase a person on each site where they were active. A view of the whole network is not done.
- **Not done yet**: a view of the journal on the screen and a network-wide view for a multisite (the journal is per site), the reports of a Content-Security-Policy (a public endpoint that anyone can write to is a risk of its own), and two-factor events.

## Known vulnerabilities

```php
'vulnerabilities' => ['enabled' => true],
```

**Off until you turn it on, because of what it sends.** For the WordPress core, each plugin and each theme, the scan asks [WPVulnerability](https://www.wpvulnerability.net) (free, no account) whether that version has a known vulnerability. What leaves the site is the **name and the version** of each plugin and theme (`/plugin/akismet/`) and the WordPress version, one request each: never the address of the site, nor anything else. But the list of what a site runs is what an attacker would like to have, so you decide. Your own themes (`scanner.own_themes`) are not asked about. A single-file plugin (`hello.php`) is not asked about either.

Each result is a finding with the CVE number when there is one, a severity from the CVSS score (critical from 9, high from 7, medium from 4; no score is medium, never low), and the version that fixes it, or "not fixed". The audit, Site Health and `pollora:doctor` show it like the other scan results; a failure stays visible when a later scan could not ask (the feed being down is "not checked", never "no vulnerability").

- The answers are kept six hours in a transient, so that a scan every hour does not ask forty questions every hour. If the feed is down, a copy of up to three days old is used, and the finding says so.
- What the feed writes is data from outside: it is cleaned, cut to a length, never taken as an instruction, and a link is kept only if it is an https address.
- WPVulnerability is a public aggregator: its data is published under CC0, the sources it gathers keep their own terms, and it has no service level. It knows what it knows: **a plugin that is absent from it is not a plugin without a vulnerability**. It is not a substitute for updating.
- Not done yet: the Composer packages of the project, the adapters for Wordfence, Patchstack and WPScan (they need your key), and an import without the network.

## Design

- **One engine.** A check returns one finding. The same report feeds Site Health, WP-CLI and `pollora:doctor`, and will feed the administration screen and the MCP abilities.
- **Hexagonal.** The domain (`Pollora\Hornet\Domain`) calls no WordPress function: the checks read the site through the `Environment` port, which `WordPressEnvironment` implements.
- **Pollora and Laravel are optional.** `illuminate/support` is a `suggest`; the provider runs only inside a Laravel application. The doctor line is registered only when `pollora/framework` is installed.
- **Secrets stay secret.** A finding names a constant; it never copies a salt, a password or a key. A test checks it against the real keys of a running WordPress.

## Quality

PHP 8.3 or later, `declare(strict_types=1)` everywhere, PHPStan, Pest (unit tests, plus integration tests against a real WordPress), the [Pollora harness](https://github.com/Pollora/harness) (`composer qa`).

## License

MIT.

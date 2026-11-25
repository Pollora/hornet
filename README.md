# Hornet

Security for WordPress, from Pollora. Hornet audits the configuration of a site, tells what to fix and how, and shows the same findings in **Tools > Site Health**, in **WP-CLI** and, on a Pollora project, in **`pollora:doctor`**.

It runs on a classic WordPress, on Bedrock and on Pollora, with no dependency on Laravel. By default it changes nothing on your site: it reads, and it tells you. Hardening and headers do nothing until you turn each one on.

> **Status: 0.3.** The audit of the configuration, the notifications, the hardening, the security headers, the login throttle and the account rules are in. The file scanner, the vulnerability feed, the administration screen, the activity log and the MCP abilities come next. Nothing here is a firewall.

## What it checks

| Check | Looks at |
| --- | --- |
| `salts` | the eight keys and salts: set, 32 characters or more, not the sample value. It names the constants and never prints a value |
| `debug-mode` | `WP_DEBUG`, `WP_DEBUG_DISPLAY`, `WP_DEBUG_LOG` on a production site |
| `https` | the site address, and `FORCE_SSL_ADMIN` |
| `file-editor` | whether the theme and plugin editor is refused, whoever refused it (`DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, Hornet's `hardening.file_editor`) |
| `file-mods` | installing code from the dashboard (advice: it also stops WordPress's own updates) |
| `config-permissions` | the permission bits of `wp-config.php` |
| `php-version` | whether the PHP branch still gets security fixes (dates from php.net, read on 2026-11-24) |
| `wordpress-version` | the update WordPress already found (no request is made) |
| `xml-rpc` | what XML-RPC answers: open, logins refused only (pingbacks still answer), or closed |
| `user-enumeration` | whether the REST API lists the users to a visitor |
| `admin-account` | a user named `admin` |
| `login-protection` | whether [pollora/portcullis](https://github.com/Pollora/portcullis) is there |
| `two-factor` | whether the official Two-Factor plugin is active |
| `two-factor-coverage` | the administrators who have not set up a second factor, named by number |

A check reads and never writes, and never asks the network. What Hornet does not know it says so (`unknown`): a file hidden by `open_basedir` is not a pass.

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

A Pollora project uses `config/hornet.php` (`php artisan vendor:publish --tag=hornet-config`), under `pollora.hornet`.

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

- **Secrets stay out of the repository and the database.** A webhook URL, a token and a signing key are written as `['env' => 'NAME']` or `['constant' => 'NAME']`. A plain string is accepted too (a Pollora `config/hornet.php` calls `env()` itself). A secret prints as `[redacted]` everywhere and an error never repeats an address.
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

## Design

- **One engine.** A check returns one finding. The same report feeds Site Health, WP-CLI and `pollora:doctor`, and will feed the administration screen and the MCP abilities.
- **Hexagonal.** The domain (`Pollora\Hornet\Domain`) calls no WordPress function: the checks read the site through the `Environment` port, which `WordPressEnvironment` implements.
- **Pollora and Laravel are optional.** `illuminate/support` is a `suggest`; the provider runs only inside a Laravel application. The doctor line is registered only when `pollora/framework` is installed.
- **Secrets stay secret.** A finding names a constant; it never copies a salt, a password or a key. A test checks it against the real keys of a running WordPress.

## Quality

PHP 8.3 or later, `declare(strict_types=1)` everywhere, PHPStan, Pest (unit tests, plus integration tests against a real WordPress), the [Pollora harness](https://github.com/Pollora/harness) (`composer qa`).

## License

MIT.

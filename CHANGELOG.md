# Changelog

All notable changes are listed here. The project follows [Semantic Versioning](https://semver.org/).

## Unreleased

Fourth milestone (0.4), last part: the themes, and the probes.

- `theme-integrity`: the themes get the first look the unknown plugins get (WordPress.org publishes no checksums for themes), in their own option. `scanner.own_themes` leaves out the themes you write yourself.
- `exposed-files`: the scan asks the site itself for about thirty fixed addresses (`.env`, `.git`, copies of `wp-config.php` and of the database, archives, logs, listings). An answer counts only when it is a 200 whose start looks like the file; the report names the address and never repeats what the file holds. `scanner.probes` (on by default, nothing is written).
- `scanner.probe_uploads` (off by default): one harmless file is written in the uploads, asked for, and removed at once, to find out whether the server runs PHP there. A file left by a run that died is swept.
- `HttpClient::get()` can be told that a private address is allowed (the site asking itself).

Fourth milestone (0.4), third part: suspicious files and the schedule.

- `wp hornet scan` walks the content folder (not the plugins, which have their own scan): a script in the uploads, an `.htaccess` or `.user.ini` that runs code, a name with a PHP extension in the middle, a hidden PHP file, and a PHP file that reads like a web shell. The rules are Hornet's own (`token_get_all`), scored: no rule is enough alone. New check `suspicious-files`.
- `scanner.ignore_paths` leaves files or folders alone; `scanner.schedule` (`off` by default, `hourly`, `twicedaily`, `daily`) runs the scan from WP-Cron under a lock. The walk has a time budget in the cron, keeps its cursor and what it found, and goes on a minute later; from the command line it is made in one go.
- In WP-Cron the run keeps to the time limit of a web request (`max_execution_time` less a margin, 45 seconds at most). The continuation of a walk runs only the walk, and each task is saved when it is done. On a network the main site schedules the scan. `ScanTask` results can say they are unfinished; the stored scan keeps the list.
- Rules for a function named by the request, a callback handed the request, and an `include` of a file the request names; the "Silence is golden" exemption is decided on tokens. Fixed on the way: turning the schedule off now takes the event out of the cron.
- A failure found earlier stays visible while a walk is in progress.

Fourth milestone (0.4), second part: the plugins.

- `wp hornet scan` compares each plugin with the list of `downloads.wordpress.org` (SHA-256 by file, one request per plugin with the slug and the version only): a changed file, a missing file, a script that is not in the list. A plugin WordPress.org does not know gets a first look, kept in the `hornet_baseline` option (no autoload) and compared afterwards; `--repin` takes the plugins as they are now.
- New check `plugin-integrity`, same rules as `core-integrity` (never scanned is `unknown`, a clean result older than seven days is low).
- A time budget of four minutes: the plugins left are named, not silently skipped.

Fourth milestone (0.4), first part: the scanner, and the core files.

- `wp hornet scan` (`--format`, `--fail-on`, `--repin`): compares the core files with the checksums of WordPress.org. It reads the disk and asks the network, only when it is run; it never deletes, repairs or quarantines a file.
- Reports a file that differs, a file that is gone, and a file that WordPress does not ship in `wp-admin`, `wp-includes` or as a script in the root. An unknown release or an unreachable WordPress.org is `unknown`, not a pass.
- `scanner.allow_modified` lists core files that are meant to differ. The files changed by the patches of `patches.lock.json` (Pollora, Bedrock) are allowed by themselves. An allowed file is pinned on first sight and a later change is reported; a pin lasts one release of the core.
- New check `core-integrity`: it shows the result of the last scan (never scanned = `unknown`; a clean result older than seven days = low), so that Site Health and `pollora:doctor` read the same story without scanning. The result is kept in one option without autoload, for the network on a multisite.
- A scan that could not look (WordPress.org did not answer, the files cannot be listed) never erases a failure an earlier scan found: the finding stays, with the date and the reason it was not checked again.
- `ignore` and `disabled_checks` apply to the scan (`core-integrity`): an ignored finding does not fail `wp hornet scan`, a disabled one is not run and asks nothing of the network.
- On a site that was never scanned, the new check is `unknown`: `wp hornet status` and the `pollora:doctor` line count one more check that "could not be verified" (no warning, no failing exit code) until `wp hornet scan` runs once.
- `Environment` gains `lastScan()`; `Finding` can be read back from its array.

Third milestone (0.3), third part: account rules.

- `passwords.check_breached` (Have I Been Pwned, k-anonymity with padding, fails open, opt-in), `session` lifetimes, `two_factor.require_roles` and `enforce_after` (an enrollment gate in the dashboard, not a login gate); all off by default.
- New check `two-factor-coverage` (accounts named by number).
- `HttpClient` gains `get()` with a size limit and a timeout.

Third milestone (0.3), second part: the login throttle.

- `login` settings (`throttle`, `max_attempts`, `window`, `lockout`, `allow_ips`) and `network` settings (`trusted_proxies`, `forwarded_header`); off by default.
- Per-address lockout, progressive up to a day, applied late on `authenticate` so that WordPress cannot undo it; failures of application passwords count; a success forgets the failures.
- It never locks an address that many visitors share (a proxy or load balancer that is not listed), and the audit raises a high finding. A forwarding header is believed only from a trusted proxy, and read from the right.
- Addresses are stored as HMACs; counters live in the object cache when there is a persistent one.
- `wp hornet unlock <ip>` and `wp hornet unlock --all`.
- The `login-protection` check accepts Hornet's own throttle and says when it cannot be trusted.
- Fixed: a `wp_salt()` call at load time made every `wp` command fail when the unlock command was registered.

Third milestone (0.3), first part: hardening and headers.

- `hardening` settings (`xmlrpc`, `user_enumeration`, `hide_version`, `file_editor`), all off by default.
- `headers` settings (`content_type_options`, `referrer_policy`, `permissions_policy`, `hsts`, `csp`), nothing sent by default; sent from `wp_headers`, `admin_init` and `login_init`, and from a Laravel middleware on Pollora.
- New check `user-enumeration`.
- Fixed: the `xml-rpc` check called XML-RPC "disabled" when only its logins were refused: pingbacks still answered. It now tells open, logins refused, and closed apart.
- The `file-editor` check reads whether the editor is really refused, whoever refused it.
- Proven over real HTTP: `tests/E2E/hardening.sh` runs WordPress itself and asserts the status codes and headers of each route with each module on and off.

## 0.2

Second milestone (0.2): notifications.

- Channels: `mail` (through `wp_mail()`), `slack`, `teams` (Workflows), `discord`, `telegram`, `ntfy` and a signed `webhook` (HMAC-SHA256, documented contract version 1).
- Settings `notifications` (`schedule`, `cooldown`, `rate_limit`, `channels`); secrets by `['env' => …]` or `['constant' => …]`, redacted everywhere, never in the database.
- The audit runs on a WP-Cron schedule and tells each channel what is new: grouped in one message, told once per cooldown, rate-limited per channel.
- Messages that fail for a passing reason are tried again (1 min, 5 min, 30 min, 2 h, honouring `Retry-After`); a refusal that cannot pass is reported, not repeated.
- Text from the site is cleaned and escaped per service; mentions are neutralised; redirects are never followed; private addresses are refused unless a channel allows them.
- `wp hornet notify run|test|status|retry`.
- Fixed in the settings check: the message of a refused address was built with the wrong operator precedence.

## 0.1 (first milestone)

First milestone (0.1): the foundation.

- Audit engine: one `Check` returns one `Finding`; the same `Report` feeds every surface.
- Twelve read-only checks of the configuration (`salts`, `debug-mode`, `https`, `file-editor`, `file-mods`, `config-permissions`, `php-version`, `wordpress-version`, `xml-rpc`, `admin-account`, `login-protection`, `two-factor`).
- Tools > Site Health: one test per check.
- `wp hornet status` (`--format`, `--fail-on`, `--all`).
- `pollora:doctor`: a "Security (Hornet)" line when `pollora/framework` is installed.
- Settings in `HORNET_CONFIG` (classic WordPress, Bedrock) or `config/hornet.php` (Pollora): `enabled`, `disabled_checks`, `ignore` (a reason is required), `site_health`; unknown keys and unknown checks are refused without stopping the site.
- Kill switch `HORNET_DISABLED`.
- Detects `pollora/portcullis` and `pollora/headless` instead of repeating their work.

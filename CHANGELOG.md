# Changelog

All notable changes are listed here. The project follows [Semantic Versioning](https://semver.org/).

## Unreleased

- **Five new checks**: `open-registration` (anyone can register with a role that may write, change others' content or take the site over), `application-passwords` (those of administrators unused for 90 days), `php-error-display` (PHP shows errors although `WP_DEBUG` is off; not on Pollora), and on Pollora only `laravel-app-key` and `laravel-debugbar`. `debug-mode` names `APP_DEBUG` there. The scan asks for `storage/logs/laravel.log` on Pollora only. New `Domain\Audit\LaravelApplication`; `Environment` has three more methods.
- Dates of the screen (journal, findings set aside) follow the format and time zone of the site, in the language of the person.

- **Fixed: the Apache rules served what is inside a dot folder** (`.git/config`, `.vscode/sftp.json`): print them again with `wp hornet server-config apache`.
- **Fixed: the Apache rules took the site down (500) when put in an `.htaccess`**, as their own header suggested. New `wp hornet server-config htaccess` for that use (mod_rewrite only).
- **Fixed: nginx served dotfiles and dumps of the uploads folder**; PHP followed by a path (`x.php/info`) is refused in uploads too.
- The scan asks for seven more addresses: SFTP and editor settings, `auth.json`, `composer.lock`, the list of installed packages, `.htaccess`, `.DS_Store`.

- **The screen was redrawn** in the colours of Pollora, with a **security score** (0 to 100, grade A to F) and what to fix first, and a visual scan. `hornet/get-status` also gives `score` and `grade`. New `Domain\Audit\SecurityScore`.

- **Fixed: on Pollora the settings of the project were never read.** The provider reads `pollora.hornet`, but the published file went to `config/hornet.php`, which Laravel loads as `hornet`: every protection looked turned on and did nothing. The file is now published to `config/pollora/hornet.php`, and a left `config/hornet.php` is reported in Site Health. Found by installing Hornet in a real Pollora project.
- **Fixed: on Pollora the REST API was not protected, and its routes did not exist.** Pollora runs the WordPress request while its own provider boots, before this package's: `/wp-json` was answered without Hornet (users listed, no screen routes) and `init` had passed. Hornet now starts on `plugins_loaded`.
- **Fixed: on Pollora, WP-CLI ignored the project's settings** and the provider ignored `HORNET_DISABLED`: both read the settings from one place (`SettingsLoader`).

- What the server writes is translated in the same 12 languages: check names, findings (summary, details, fix), scan tasks, settings rows, menu and page title, Site Health, login and password messages, the findings of notifications (site language). WP-CLI, abilities and `pollora:doctor` stay in English. Findings are stored in English with a translatable form; older stored scans are read as before. New: `Domain\I18n\Message`, `Translator`, `WpTranslator`.

- The Tools > Security screen is translated in 12 languages (fr, es, de, pt-BR, it, nl, ja, zh-CN, ko, ru, pl, tr), machine-made, to be reviewed; what the server writes is translated too (see below). Counts use plural forms (`1 open finding.`, not `1 open findings.`). `npm run i18n` / `i18n:check`.

From the security audit of the package (an independent reviewer, three reproductions):

- **The login lock** no longer forgets the failures at a successful login: somebody with an account of their own could slip a login between four guesses at another account and never reach the limit. The failures end with their window. An IPv6 network is counted as its /64.
- **Suspicious files**: a folder that is merely named `cache` or `wflogs` (deep in the uploads), and the `languages` folder, are walked again; only the caches at the root of the content folder, `node_modules`, `.git` and the plugins (their own scan) are left. A script larger than 512 KB is no longer excused: its first 512 KB are scored.

Ninth milestone (0.9), first part: rules for the web server.

- `wp hornet server-config nginx|apache [--uploads=<path>] [--headers]` prints rules (no PHP from uploads, no dotfile, no file that should never be public, optional static headers). It writes nothing. The folder is validated before it enters a configuration.

Eighth milestone (0.8): abilities for AI agents.

- `abilities.enabled` (off) publishes `hornet/get-status`, `hornet/list-findings`, `hornet/get-remediation` and `hornet/read-journal` in the Abilities API (WordPress 6.9 and later, offered to the MCP Adapter); `abilities.writes` (off, and refused without `enabled`) adds `hornet/run-scan`, `hornet/set-aside-finding` and `hornet/restore-finding`: 20 an hour per account, each said in the journal (`hornet.scan_requested` is new).
- No ability changes a setting, turns a protection off or reads a secret. What an agent reads is cut, cleaned and copied field by field (`AgentView`).
- Requires `pollora/abilities` (^1.0, no dependency of its own).
- `AdminBackend::requestScan()` is what the screen and the ability share.

Seventh milestone (0.7), second part: the journal on the screen, and setting a finding aside.

- **Journal** tab on the screen (`GET hornet/v1/journal`: filters by kind and source validated, 100 per page at most; says it is off when it is).
- **Set aside…** / **Bring it back** on a finding (`POST` and `DELETE hornet/v1/findings/{check}/ignore`): a written reason of at least 8 characters, kept with who and when, journaled; only for a finding that is open; the settings file wins over the screen, and a check that no longer exists is left out rather than refused. Every surface (screen, Site Health, `wp hornet`, notifications) goes through `AuditorFactory`, so they all see it.
- Site Health builds its auditor when it is asked for: the database is not read on other requests.

Seventh milestone (0.7), first part: the journal.

- `journal.enabled` (off by default) and `journal.retention_days` (90): a table per site (`{prefix}hornet_journal`, `dbDelta`, versioned) that records logins and failures, accounts created, deleted and given a role, application passwords (by name), plugins and themes (activated, deactivated, deleted, installed, updated), the core update, sensitive options (`siteurl`, `home`, `users_can_register`, `default_role`, and that `admin_email` changed), and the end of a scan. Each event has the **source of the request**: web, admin, rest, ajax, xmlrpc, cli or cron.
- Never a password, a secret, or the name typed on a login that matches no account. The address is cut (IPv4 /24, IPv6 /48). A journal that cannot be written never stops a request.
- What a visitor can cause is bounded: 5 failed logins per network per 10 minutes, 120 per hour for the site, each limit said once by a `login.flood` event (about 125 rows an hour at worst). A failed login has nobody as its author, and the account it aimed at as its subject. The export gives the network and the source only of what the person did themselves.
- A daily purge (`pollora/hornet/purge_journal`), an exporter and an eraser for the privacy tools of WordPress (erasing keeps the events and takes the person out of them), a suggestion for the privacy policy.
- `wp hornet journal list|purge|drop`.
- New hook `pollora/hornet/scan_completed`. `ScanJob::run()` returns `null` when it did not run.

Sixth milestone (0.6): the administration screen.

- **Tools > Security** (on a network: Settings, in the network administration, for the super administrators): overview and **Scan now**, findings (a table, worst first, with a search, filters and the details with what to do), settings (read only, never a secret), notification channels with a test message. `admin => false` removes it.
- REST routes `hornet/v1/status`, `hornet/v1/scan` (GET state, POST to ask) and `hornet/v1/notify/test`, for the administrators, with the nonce of the session. A scan that is asked for runs in WP-Cron and tells which task it is at; two never run at once; a scan that died is not waited for.
- `ScanJob` runs the scan under its lock and tells its progress; `ScanModule` uses it, for the schedule, the continuation and `pollora/hornet/scan_now`.
- The screen is built with the public components of WordPress only (no DataViews: it relies on private APIs of the core, and WordPress 6.9 refuses them), about 13 KB, WordPress 6.9 and later. It says so on the page when a script it needs is missing. The routes are not listed in `/wp-json/`.
- The screen is compiled from `admin/src` with `@wordpress/scripts` into `assets/admin`, which is tracked (nothing runs npm on a site); the package serves the files itself, to the people who may see the screen. A workflow checks that what is tracked is what the source builds to.
- `Scanner` accepts a callback that is told which task it is about to run.

Fifth milestone (0.5): known vulnerabilities.

- `vulnerabilities.enabled` (off by default): the scan asks WPVulnerability whether the core, each plugin and each theme has a known vulnerability in the version the site runs. Only the name and the version are sent. New check `vulnerabilities`, present only when the lookup is on.
- A finding per scan with the CVE number, a severity from the CVSS score, and the version that fixes it (or "not fixed"). A feed that does not answer is "not checked", never "no vulnerability", and an earlier failure stays visible.
- Answers are cached six hours; a copy of up to three days is used when the feed is down, and the finding says so. Text from the feed is cleaned and cut; a link is kept only if it is https.
- `DefaultChecks::all()` takes whether the lookup is on.

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
- Settings in `HORNET_CONFIG` (classic WordPress, Bedrock) or `config/pollora/hornet.php` (Pollora): `enabled`, `disabled_checks`, `ignore` (a reason is required), `site_health`; unknown keys and unknown checks are refused without stopping the site.
- Kill switch `HORNET_DISABLED`.
- Detects `pollora/portcullis` and `pollora/headless` instead of repeating their work.

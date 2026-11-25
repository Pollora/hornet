# Changelog

All notable changes are listed here. The project follows [Semantic Versioning](https://semver.org/).

## Unreleased

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

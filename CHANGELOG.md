# Changelog

All notable changes are listed here. The project follows [Semantic Versioning](https://semver.org/).

## Unreleased

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

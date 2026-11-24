# Changelog

All notable changes are listed here. The project follows [Semantic Versioning](https://semver.org/).

## Unreleased

First milestone (0.1): the foundation.

- Audit engine: one `Check` returns one `Finding`; the same `Report` feeds every surface.
- Twelve read-only checks of the configuration (`salts`, `debug-mode`, `https`, `file-editor`, `file-mods`, `config-permissions`, `php-version`, `wordpress-version`, `xml-rpc`, `admin-account`, `login-protection`, `two-factor`).
- Tools > Site Health: one test per check.
- `wp hornet status` (`--format`, `--fail-on`, `--all`).
- `pollora:doctor`: a "Security (Hornet)" line when `pollora/framework` is installed.
- Settings in `HORNET_CONFIG` (classic WordPress, Bedrock) or `config/hornet.php` (Pollora): `enabled`, `disabled_checks`, `ignore` (a reason is required), `site_health`; unknown keys and unknown checks are refused without stopping the site.
- Kill switch `HORNET_DISABLED`.
- Detects `pollora/portcullis` and `pollora/headless` instead of repeating their work.

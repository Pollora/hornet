# Changelog

All notable changes are listed here. The project follows [Semantic Versioning](https://semver.org/). Until 1.0, a minor version may change the public surface (settings, commands, hooks, abilities, REST routes, webhook payload); the changelog says so.

## Unreleased

### Added

- `hardening.restrict_rest_api` (off by default): the REST API answers 401 to a visitor who is not logged in, with an allow list (`hardening.rest_api_allow`) on top of the defaults. The `user-enumeration` check follows it.
- A Hornet section in **Tools > Site Health > Info** (version, what is turned on, last scan, score). What is turned on and the score are private: they are left out of the copy.

## 0.9.0 - 2026-11-26

First published version. Security for WordPress (classic, Bedrock, Pollora): Hornet reads, tells what to fix and how, and changes nothing until you ask it to.

### Audit and score

- Checks of the configuration (keys and salts, debug and PHP errors, HTTPS, file editor and file changes, `wp-config.php` permissions, PHP and WordPress versions, XML-RPC, user enumeration, open registration), of the accounts (default `admin`, two-factor, application passwords, new and dormant administrators), of plugins and themes (updates, inactive ones) and, on Pollora only, of the Laravel application (`APP_KEY`, `APP_DEBUG`, Debugbar).
- A security score (0 to 100, grade A to F) with what to fix first. A finding can be set aside, with a written reason.
- The same findings in **Tools > Security**, Site Health, `wp hornet status` and, on Pollora, `pollora:doctor`.

### Scan

- Core, plugin and theme files compared with WordPress.org or with a first look; suspicious files; disguised JavaScript in the database; files the site gives away; security headers of the home page; known vulnerabilities (opt-in: the names and versions are sent).
- On demand, from the screen or `wp hornet scan`, or scheduled. Resumable on a time budget. It never changes, deletes or quarantines anything.

### Protection (all off until turned on)

- Hardening (XML-RPC, user enumeration, version, file editor), security headers and a Content-Security-Policy (report-only or enforced), a login throttle when `pollora/portcullis` is not there, breached-password refusal, session lifetime, two-factor required by role (with the Two-Factor plugin).
- `wp hornet server-config nginx|apache|htaccess` prints web server rules; it writes nothing.

### Telling

- Notifications (e-mail, Slack, Microsoft Teams, Discord, Telegram, ntfy, signed webhook), de-duplicated and rate limited.
- An activity journal (opt-in), with the source of each request, retention and the WordPress privacy tools.
- Abilities for AI agents (opt-in, reading and writing separately).
- Screen and server texts in 12 languages besides English.

### Known limitations

- The translations are machine-made and have not been reviewed.
- Not verified: multisite on a real network (the integration suite runs a single site), Windows.
- On a network, the scan reads the tables of the main site only.
- The login throttle: its counters are not atomic (a burst can get a few tries more); a site behind a CDN that is not listed in `network.trusted_proxies` can lock visitors who share an edge address (the audit says so).
- Requiring two-factor sends browsers to their profile; it does not gate the REST API or XML-RPC.
- The database scan does not read post meta, nor the middle of a text longer than 256 KB; it is not a signature-based malware scanner.
- The scan that the screen asks for runs in the scheduled tasks of WordPress: where `DISABLE_WP_CRON` is set (Pollora), the server needs a cron.

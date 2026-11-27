# Changelog

All notable changes are listed here. The project follows [Semantic Versioning](https://semver.org/). Until 1.0, a minor version may change the public surface (settings, commands, hooks, abilities, REST routes, webhook payload); the changelog says so.

## Unreleased

## 0.10.3 - 2026-11-27

**Upgrade, then run a scan (`wp hornet scan`):** the file scan could report as clean some files it should report. Fixes in what it reads and in what it keeps between two runs.

### Fixed

- The PHP rules of the file scan read the different ways of writing a function or a variable name consistently, request input included.
- Development only: `antecedent/patchwork` is required at commit `d588654` of its `master` branch (antecedent/patchwork#235), aliased as 2.2.6, so that a mutation run no longer counts crashing tests as caught mutants. Temporary, until the next Patchwork release.
- An ntfy topic address written with a trailing slash now posts to the server, not to the topic's own address.
- A file name that is not valid UTF-8 is now kept between two runs of a scan, and shown in notifications and in the JSON output of the commands.

## 0.10.2 - 2026-11-27

**Upgrade if Hornet runs on a multisite network.** Two things change there: the login locks made before the upgrade are forgotten, and super administrators are now covered by `two_factor.require_roles` when it names `administrator`, so one without a second factor is sent to set it up.

Fixes for multisite networks, tried on one for the first time. **Upgrade if you run Hornet on a network.**

### Changed

- On a network, the login throttle counts, locks and unlocks for the whole network. Locks made before the upgrade are forgotten.
- On a network, `two_factor.require_roles` with `administrator` applies to the super administrators, with or without a role on the site, and the audit counts them (second factor, application passwords).
- On a network, `wp hornet scan` is refused on a site that is not the main one.

### Fixed

- On a network, the `file-editor` check said that the editor was disabled whatever the settings.
- On a network, a scan run as another site wrote what it saw of that site over the result of the network.
- On a network, the journal now says that an account was added to a site, removed from one or deleted from the network, and writes what is done to another site in that site's journal. Taking an account off a site is no longer written as its deletion.
- On a network, the journal table of a deleted site is dropped even when the journal is off on the site it is deleted from.
- The index of the REST API (`/wp-json/`) no longer lists the route of the `hornet/v1` namespace itself.

## 0.10.1 - 2026-11-27

Bug fixes. **Upgrade if you rely on the scheduled scan or on notifications:** in 0.9.0 and 0.10.0 a run that died could stop all the following ones. A lock left that way is taken over by the first run after the upgrade; there is nothing to do by hand.

### Fixed

- A journal the database cannot read is no longer taken for an empty one: the checks on new and dormant administrators say they could not run, and the screen, `wp hornet journal`, the ability and the privacy export report the failure.
- The lock of a scan or of a notification run that died is taken over after ten minutes, as intended: it was only seen as old within the request that had set it, since the database gives the time back as text, so a crashed run could hold its job for good.
- A waiting notification whose stored creation date is damaged is dropped like any other damaged entry, instead of making every retry fail.
- Damaged data is no longer read in part or ends in a fatal error: a damaged cache of the OSV answers is asked again instead of passing for fewer or no vulnerabilities; a journal line gives only the details an event may hold; a database row that cannot be read makes the content scan say so; a `pollora.hornet` configuration that is not an array is reported in Site Health.

## 0.10.0 - 2026-11-27

### Before you upgrade

- The scan now sends the **folder name** of each plugin to `api.wordpress.org`, to tell whether it was closed or left behind. `'disabled_checks' => ['abandoned-plugins']` stops it.
- With the journal on, a refused second factor is `login.second_factor_failed`, no longer `login.failed`: update an alert or a filter that counts `login.failed`.

### Added

- `hardening.restrict_rest_api` (off by default): the REST API answers 401 to a visitor who is not logged in, with an allow list (`hardening.rest_api_allow`) on top of the defaults. The `user-enumeration` check follows it.
- A Hornet section in **Tools > Site Health > Info** (version, what is turned on, last scan, score). What is turned on and the score are private: they are left out of the copy.
- The scan reports plugins that WordPress.org has closed (high) or that had no release there for two years or more (low): the `abandoned-plugins` check. It sends the folder name of each plugin to `api.wordpress.org`; `disabled_checks` stops it.
- Known vulnerabilities of the Composer packages of the project, asked to OSV: the `composer-vulnerabilities` check, off until `vulnerabilities.composer` (new setting) and `vulnerabilities.enabled` are both on. Only the packages installed from packagist.org are sent.
- Two-factor events in the journal (Two-Factor plugin): `login.second_factor_passed`, `login.second_factor_failed`, `user.second_factor_enabled`, `user.second_factor_disabled`.

### Changed

- The settings row of the notification channels (screen and Site Health) says how often the audit runs in words (`1 channel, no scheduled audit`), translated, instead of `1, off`, which read as a channel turned off.
- The settings row of the scheduled scan says its frequency in words (`once a day`), translated, instead of the raw `daily`; when it is not scheduled it reads `Off` only, no longer `Off` then `off`.
- With the journal on, a refused second factor is `login.second_factor_failed` (see above); a `login.success` that still has a second factor to pass says `second_factor: pending`.

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

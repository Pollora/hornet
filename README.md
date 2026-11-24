# Hornet

Security for WordPress, from Pollora. Hornet audits the configuration of a site, tells what to fix and how, and shows the same findings in **Tools > Site Health**, in **WP-CLI** and, on a Pollora project, in **`pollora:doctor`**.

It runs on a classic WordPress, on Bedrock and on Pollora, with no dependency on Laravel. It changes nothing on your site: version 0.1 only reads.

> **Status: 0.1, the foundation.** The audit of the configuration is in. Hardening, notifications, the file scanner, the vulnerability feed, the administration screen, the activity log and the MCP abilities come in the next releases. Nothing here is a firewall.

## What it checks

| Check | Looks at |
| --- | --- |
| `salts` | the eight keys and salts: set, 32 characters or more, not the sample value. It names the constants and never prints a value |
| `debug-mode` | `WP_DEBUG`, `WP_DEBUG_DISPLAY`, `WP_DEBUG_LOG` on a production site |
| `https` | the site address, and `FORCE_SSL_ADMIN` |
| `file-editor` | `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS` |
| `file-mods` | installing code from the dashboard (advice: it also stops WordPress's own updates) |
| `config-permissions` | the permission bits of `wp-config.php` |
| `php-version` | whether the PHP branch still gets security fixes (dates from php.net, read on 2026-11-24) |
| `wordpress-version` | the update WordPress already found (no request is made) |
| `xml-rpc` | whether XML-RPC answers, whoever turned it off |
| `admin-account` | a user named `admin` |
| `login-protection` | whether [pollora/portcullis](https://github.com/Pollora/portcullis) is there |
| `two-factor` | whether the official Two-Factor plugin is active |

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

## Design

- **One engine.** A check returns one finding. The same report feeds Site Health, WP-CLI and `pollora:doctor`, and will feed the administration screen and the MCP abilities.
- **Hexagonal.** The domain (`Pollora\Hornet\Domain`) calls no WordPress function: the checks read the site through the `Environment` port, which `WordPressEnvironment` implements.
- **Pollora and Laravel are optional.** `illuminate/support` is a `suggest`; the provider runs only inside a Laravel application. The doctor line is registered only when `pollora/framework` is installed.
- **Secrets stay secret.** A finding names a constant; it never copies a salt, a password or a key. A test checks it against the real keys of a running WordPress.

## Quality

PHP 8.3 or later, `declare(strict_types=1)` everywhere, PHPStan, Pest (unit tests, plus integration tests against a real WordPress), the [Pollora harness](https://github.com/Pollora/harness) (`composer qa`).

## License

MIT.

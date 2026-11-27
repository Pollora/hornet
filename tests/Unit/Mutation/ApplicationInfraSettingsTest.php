<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Http\Context;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Infrastructure\Config\EnvironmentSecrets;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Config\SettingsLoader;
use Pollora\Hornet\Tests\Fixtures\ArraySecrets;

/**
 * @param  array<mixed>  $config
 */
function mutAppSettings(array $config): Settings
{
    return Settings::fromArray($config, new ArraySecrets);
}

/**
 * @param  array<string, mixed>  $channel
 */
function mutAppChannel(array $channel, string $name = 'chat'): Settings
{
    return mutAppSettings(['notifications' => ['channels' => [$name => $channel]]]);
}

it('takes a whole number within its bounds, both included, and refuses the next one out', function (string $section, string $key, int $min, int $max): void {
    $config = static fn (mixed $value): array => $section === 'channels' ? [] : [$section => [$key => $value]];

    mutAppSettings($config($min));
    mutAppSettings($config($max));

    foreach ([$min - 1, $max + 1, (string) $min, 1.5] as $bad) {
        expect(fn () => mutAppSettings($config($bad)))->toThrow(InvalidSettingsException::class, $key);
    }
})->with([
    ['notifications', 'cooldown', 60, 2592000],
    ['notifications', 'rate_limit', 1, 1000],
    ['login', 'max_attempts', 1, 100],
    ['login', 'window', 60, 86400],
    ['login', 'lockout', 60, 86400],
    ['journal', 'retention_days', 1, 3650],
    ['passwords', 'breached_threshold', 1, 1000000],
    ['session', 'lifetime', 900, 1209600],
    ['session', 'remember_lifetime', 900, 31536000],
]);

it('has the documented defaults', function (): void {
    $settings = mutAppSettings([]);

    expect($settings->enabled)->toBeTrue()
        ->and($settings->siteHealth)->toBeTrue()
        ->and($settings->admin)->toBeTrue()
        ->and([$settings->notifications->schedule, $settings->notifications->cooldown, $settings->notifications->rateLimit, $settings->notifications->channels])->toBe(['daily', 86400, 20, []])
        ->and([$settings->journal->enabled, $settings->journal->retentionDays])->toBe([false, 90])
        ->and([$settings->scanner->schedule, $settings->scanner->probes, $settings->scanner->probeUploads, $settings->scanner->allowModified, $settings->scanner->ignorePaths, $settings->scanner->ownThemes, $settings->scanner->ignoreContent])->toBe(['off', true, false, [], [], [], []])
        ->and([$settings->vulnerabilities->enabled, $settings->vulnerabilities->composer])->toBe([false, false])
        ->and([$settings->abilities->enabled, $settings->abilities->writes])->toBe([false, false])
        ->and([$settings->accounts->checkBreached, $settings->accounts->breachedThreshold, $settings->accounts->sessionLifetime, $settings->accounts->rememberLifetime, $settings->accounts->requireRoles, $settings->accounts->enforceAfter])->toBe([false, 1, null, null, [], null])
        ->and($settings->accounts->isActive())->toBeFalse()
        ->and($settings->login->throttle)->toBeFalse()
        ->and($settings->hardening->isActive())->toBeFalse()
        ->and([$settings->hardening->restrictRestApi, $settings->hardening->restApiAllow])->toBe([false, []]);
});

it('has the same defaults in a section that is there but empty', function (): void {
    $settings = mutAppSettings(['notifications' => [], 'journal' => [], 'scanner' => [], 'vulnerabilities' => [], 'abilities' => [], 'login' => [], 'hardening' => [], 'passwords' => [], 'session' => [], 'two_factor' => [], 'headers' => [], 'network' => []]);

    expect([$settings->notifications->schedule, $settings->notifications->cooldown, $settings->notifications->rateLimit])->toBe(['daily', 86400, 20])
        ->and([$settings->journal->enabled, $settings->journal->retentionDays])->toBe([false, 90])
        ->and([$settings->scanner->schedule, $settings->scanner->probes, $settings->scanner->probeUploads])->toBe(['off', true, false])
        ->and([$settings->vulnerabilities->enabled, $settings->vulnerabilities->composer])->toBe([false, false])
        ->and([$settings->login->throttle, $settings->login->policy->maxAttempts, $settings->login->policy->window, $settings->login->policy->lockout])->toBe([false, 5, 900, 900])
        ->and($settings->accounts->isActive())->toBeFalse();
});

it('refuses a section that is not a list of settings, and a key it does not know', function (string $section): void {
    expect(fn () => mutAppSettings([$section => 'x']))->toThrow(InvalidSettingsException::class, $section)
        ->and(fn () => mutAppSettings([$section => ['typo' => 1]]))->toThrow(InvalidSettingsException::class, 'typo');
})->with(['notifications', 'journal', 'scanner', 'vulnerabilities', 'abilities', 'login', 'hardening', 'passwords', 'session', 'two_factor', 'headers', 'network']);

it('refuses a flag that is not true or false', function (array $config, string $named): void {
    expect(fn () => mutAppSettings($config))->toThrow(InvalidSettingsException::class, $named);
})->with([
    [['enabled' => 1], 'enabled'],
    [['site_health' => 'yes'], 'site_health'],
    [['admin' => 0], 'admin'],
    [['journal' => ['enabled' => 1]], 'journal.enabled'],
    [['vulnerabilities' => ['enabled' => 1]], 'vulnerabilities.enabled'],
    [['vulnerabilities' => ['composer' => 1]], 'vulnerabilities.composer'],
    [['abilities' => ['enabled' => 1]], 'abilities.enabled'],
    [['abilities' => ['writes' => 1]], 'abilities.writes'],
    [['abilities' => ['writes' => true]], 'abilities.writes'],
    [['login' => ['throttle' => 1]], 'login.throttle'],
    [['passwords' => ['check_breached' => 1]], 'passwords.check_breached'],
    [['scanner' => ['probes' => 1]], 'scanner.probes'],
    [['scanner' => ['probe_uploads' => 'yes']], 'scanner.probe_uploads'],
    [['scanner' => ['schedule' => 'weekly']], 'scanner.schedule'],
    [['scanner' => ['schedule' => 1]], 'scanner.schedule'],
    [['notifications' => ['schedule' => 'weekly']], 'notifications.schedule'],
    [['notifications' => ['channels' => 'x']], 'notifications.channels'],
    [['hardening' => ['xmlrpc' => 'yes']], 'hardening.xmlrpc'],
    [['disabled_checks' => 'salts'], 'disabled_checks'],
    [['disabled_checks' => ['a' => 'salts']], 'disabled_checks'],
    [['disabled_checks' => ['']], 'disabled_checks'],
    [['disabled_checks' => [5]], 'disabled_checks'],
    [['ignore' => 'x'], 'ignore'],
    [['ignore' => ['a' => ['check' => 'x', 'reason' => 'y']]], 'ignore'],
    [['ignore' => ['x']], 'ignore'],
    [['ignore' => [['check' => '', 'reason' => 'why']]], 'ignore'],
    [['ignore' => [['check' => 'x', 'reason' => '  ']]], 'ignore'],
    [['ignore' => [['check' => 'x']]], 'ignore'],
    [['ignore' => [['reason' => 'why']]], 'ignore'],
]);

it('reads what turns a protection on', function (): void {
    $settings = mutAppSettings(['enabled' => false, 'disabled_checks' => ['a', 'b'], 'ignore' => [['check' => 'c', 'reason' => ' why ']], 'site_health' => false, 'admin' => false,
        'abilities' => ['enabled' => true, 'writes' => true], 'vulnerabilities' => ['enabled' => true, 'composer' => true]]);

    expect([$settings->enabled, $settings->disabledChecks, $settings->ignored, $settings->siteHealth, $settings->admin])->toBe([false, ['a', 'b'], ['c' => 'why'], false, false])
        ->and([$settings->abilities->enabled, $settings->abilities->writes])->toBe([true, true])
        ->and($settings->vulnerabilities->auditsPackages())->toBeTrue()
        ->and(mutAppSettings(['vulnerabilities' => ['composer' => true]])->vulnerabilities->auditsPackages())->toBeFalse()
        ->and(mutAppSettings(['vulnerabilities' => ['enabled' => true]])->vulnerabilities->auditsPackages())->toBeFalse();
});

it('takes any one account rule as active', function (array $config): void {
    expect(mutAppSettings($config)->accounts->isActive())->toBeTrue();
})->with([
    [['passwords' => ['check_breached' => true]]],
    [['session' => ['lifetime' => 900]]],
    [['session' => ['remember_lifetime' => 900]]],
    [['two_factor' => ['require_roles' => ['administrator']]]],
]);

it('takes role names and a real date only', function (): void {
    $accounts = mutAppSettings(['two_factor' => ['require_roles' => ['administrator', 'shop_manager-2'], 'enforce_after' => '2026-02-28']])->accounts;

    expect($accounts->requireRoles)->toBe(['administrator', 'shop_manager-2'])
        ->and($accounts->enforceAfter?->format('Y-m-d H:i:s e'))->toBe('2026-02-28 00:00:00 UTC');

    foreach (['administrator', ['a' => 'x'], [5], ['Admin'], ['a b'], ["admin\n"], [str_repeat('a', 61)]] as $bad) {
        expect(fn () => mutAppSettings(['two_factor' => ['require_roles' => $bad]]))->toThrow(InvalidSettingsException::class, 'require_roles');
    }

    foreach (['2026-02-29', '2026-2-1', "2026-02-01\n", 'x2026-02-01', 'tomorrow', 20260201] as $bad) {
        expect(fn () => mutAppSettings(['two_factor' => ['enforce_after' => $bad]]))->toThrow(InvalidSettingsException::class, 'enforce_after');
    }
});

it('takes only plain paths, theme folders and database entries for what the scan leaves alone', function (): void {
    $scanner = mutAppSettings(['scanner' => ['allow_modified' => ['wp-includes/l10n.php'], 'ignore_paths' => ['themes/x/vendor/'], 'own_themes' => ['My-theme_2.0'], 'ignore_content' => ['post:12', 'comment:3', 'user:5', 'option:widget_text'], 'schedule' => 'hourly', 'probes' => false, 'probe_uploads' => true]])->scanner;

    expect($scanner->allowModified)->toBe(['wp-includes/l10n.php'])
        ->and($scanner->ignorePaths)->toBe(['themes/x/vendor'])
        ->and($scanner->ownThemes)->toBe(['My-theme_2.0'])
        ->and($scanner->ignoreContent)->toBe(['post:12', 'comment:3', 'user:5', 'option:widget_text'])
        ->and([$scanner->schedule, $scanner->probes, $scanner->probeUploads])->toBe(['hourly', false, true]);

    foreach (['allow_modified' => ['x', ['../x'], ['/etc'], [5], ['a' => 'x']], 'ignore_paths' => ['x', ['..'], [''], [null]], 'own_themes' => ['x', ['a/b'], ['.x'], ["a\n"], [5], ['a' => 'x'], [' a']], 'ignore_content' => ['x', ['post:0'], ['post:1x'], ['xpost:1'], ["post:1\n"], ['page:1'], ['option:a b'], ["option:a\n"], ['option:'.str_repeat('a', 65)], [5], ['a' => 'post:1']]] as $key => $values) {
        foreach ($values as $bad) {
            expect(fn () => mutAppSettings(['scanner' => [$key => $bad]]))->toThrow(InvalidSettingsException::class, 'scanner.'.$key);
        }
    }
});

it('reads the headers it is told to send', function (): void {
    $headers = mutAppSettings(['headers' => ['content_type_options' => true, 'referrer_policy' => 'no-referrer', 'permissions_policy' => 'camera=()', 'hsts' => ['max_age' => 600], 'csp' => ['policy' => "default-src 'self'"]]])->headers;

    expect($headers->for(Context::Front, true))->toHaveKeys(['X-Content-Type-Options', 'Referrer-Policy', 'Permissions-Policy', 'Strict-Transport-Security', 'Content-Security-Policy-Report-Only'])
        ->and(mutAppSettings(['headers' => []])->headers->for(Context::Front, true))->toBe([])
        ->and(mutAppSettings(['headers' => ['hsts' => ['max_age' => 600]]])->headers->for(Context::Front, true))->toBe(['Strict-Transport-Security' => 'max-age=600'])
        ->and(mutAppSettings(['headers' => ['hsts' => ['max_age' => 31536000, 'include_subdomains' => true, 'preload' => true]]])->headers->for(Context::Front, true))->toBe(['Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload'])
        ->and(mutAppSettings(['headers' => ['csp' => ['mode' => 'enforce', 'policy' => "default-src 'self'"]]])->headers->for(Context::Front, true))->toHaveKey('Content-Security-Policy')
        ->and(mutAppSettings(['headers' => ['csp' => ['policy' => "default-src 'self'"]]])->headers->for(Context::Admin, true))->toBe([]);
});

it('refuses headers it cannot read', function (array $headers, string $named): void {
    expect(fn () => mutAppSettings(['headers' => $headers]))->toThrow(InvalidSettingsException::class, $named);
})->with([
    [['content_type_options' => 'yes'], 'headers.content_type_options'],
    [['referrer_policy' => 5], 'headers.referrer_policy'],
    [['permissions_policy' => true], 'headers.permissions_policy'],
    [['hsts' => 'x'], 'headers.hsts'],
    [['hsts' => []], 'headers.hsts'],
    [['hsts' => ['max_age' => '5']], 'headers.hsts'],
    [['hsts' => ['max_age' => 5, 'typo' => 1]], 'typo'],
    [['hsts' => ['max_age' => 5, 'include_subdomains' => 1]], 'headers.hsts'],
    [['hsts' => ['max_age' => 5, 'preload' => 1]], 'headers.hsts'],
    [['csp' => 'x'], 'headers.csp'],
    [['csp' => []], 'headers.csp'],
    [['csp' => ['policy' => 5]], 'headers.csp'],
    [['csp' => ['policy' => 'a', 'typo' => 1]], 'typo'],
    [['csp' => ['policy' => 'a', 'mode' => 'off']], 'headers.csp'],
    [['csp' => ['policy' => 'a', 'admin' => 1]], 'headers.csp'],
    [['csp' => ['policy' => 'a', 'report_uri' => 5]], 'headers.csp'],
]);

it('reads the proxies in front of the site', function (): void {
    expect(mutAppSettings(['network' => ['trusted_proxies' => ['10.0.0.0/8'], 'forwarded_header' => 'X-Real-IP']])->network->header())->toBe('X-Real-IP')
        ->and(mutAppSettings(['network' => []])->network->header())->toBe('X-Forwarded-For')
        ->and(fn () => mutAppSettings(['network' => ['forwarded_header' => 'X-Other']]))->toThrow(InvalidSettingsException::class, 'forwarded_header')
        ->and(fn () => mutAppSettings(['network' => ['forwarded_header' => 5]]))->toThrow(InvalidSettingsException::class, 'forwarded_header')
        ->and(fn () => mutAppSettings(['network' => ['trusted_proxies' => 'x']]))->toThrow(InvalidSettingsException::class, 'network.trusted_proxies')
        ->and(fn () => mutAppSettings(['network' => ['trusted_proxies' => ['a' => '10.0.0.1']]]))->toThrow(InvalidSettingsException::class, 'network.trusted_proxies')
        ->and(fn () => mutAppSettings(['network' => ['trusted_proxies' => [5]]]))->toThrow(InvalidSettingsException::class, 'network.trusted_proxies');
});

it('lets the listed addresses through the throttle, and no other', function (): void {
    $login = mutAppSettings(['login' => ['throttle' => true, 'allow_ips' => ['198.51.100.0/24', '203.0.113.7']]])->login;

    expect($login->throttle)->toBeTrue()
        ->and($login->isAllowed('198.51.100.9'))->toBeTrue()
        ->and($login->isAllowed('203.0.113.7'))->toBeTrue()
        ->and($login->isAllowed('203.0.113.8'))->toBeFalse();
});

it('keeps open the routes visitors need, without the batch route', function (): void {
    $hardening = mutAppSettings(['hardening' => ['restrict_rest_api' => true, 'rest_api_allow' => ['/wp/v2/posts/', 'my.plugin/v1', 'batchx']]])->hardening;

    expect($hardening->restrictRestApi)->toBeTrue()
        ->and($hardening->restApiAllow)->toBe(['wp/v2/posts', 'my.plugin/v1', 'batchx']);

    foreach (['x', ['a' => 'wp'], [''], [5], ['batch'], ['batch/v1'], ['BATCH/v1'], ['.hidden'], ['a//b'], ["a\n"], ['a/.b'], ['a b']] as $bad) {
        expect(fn () => mutAppSettings(['hardening' => ['rest_api_allow' => $bad]]))->toThrow(InvalidSettingsException::class, 'rest_api_allow');
    }
});

// Channels

it('names the kinds of channel when the type is not one', function (): void {
    expect(fn () => mutAppChannel(['type' => 'pager']))->toThrow(InvalidSettingsException::class, 'mail, webhook, slack, teams, discord, telegram, ntfy')
        ->and(fn () => mutAppChannel(['type' => 5]))->toThrow(InvalidSettingsException::class, 'type')
        ->and(fn () => mutAppChannel([]))->toThrow(InvalidSettingsException::class, 'type');
});

it('takes a short lowercase name only', function (string $name, bool $taken): void {
    $build = static fn () => mutAppChannel(['type' => 'mail', 'to' => 'a@example.com'], $name);

    $taken ? expect($build()->notifications->channels[0]->name)->toBe($name) : expect($build)->toThrow(InvalidSettingsException::class, 'channel name');
})->with([['a', true], ['0-_', true], [str_repeat('a', 40), true], [str_repeat('a', 41), false], ['-a', false], ['A', false], ['a b', false], ["a\n", false]]);

it('judges the severity of a channel, medium unless told', function (): void {
    expect(mutAppChannel(['type' => 'mail', 'to' => 'a@example.com'])->notifications->channels[0]->minSeverity)->toBe(Severity::Medium)
        ->and(mutAppChannel(['type' => 'mail', 'to' => 'a@example.com', 'min_severity' => 'critical'])->notifications->channels[0]->minSeverity)->toBe(Severity::Critical)
        ->and(fn () => mutAppChannel(['type' => 'mail', 'to' => 'a@example.com', 'min_severity' => 'loud']))->toThrow(InvalidSettingsException::class, 'min_severity')
        ->and(fn () => mutAppSettings(['notifications' => ['channels' => ['c' => 'x']]]))->toThrow(InvalidSettingsException::class, 'array');
});

it('requires what each kind of channel needs', function (array $channel, string $named): void {
    expect(fn () => mutAppChannel($channel))->toThrow(InvalidSettingsException::class, $named);
})->with([
    [['type' => 'mail'], '"to" is required'],
    [['type' => 'slack'], '"url" is required'],
    [['type' => 'teams'], '"url" is required'],
    [['type' => 'discord'], '"url" is required'],
    [['type' => 'ntfy'], '"url" is required'],
    [['type' => 'webhook', 'url' => 'https://a.example'], '"secret" is required'],
    [['type' => 'telegram', 'chat_id' => '1'], '"token" is required'],
    [['type' => 'telegram', 'token' => 't'], '"chat_id" is required'],
    [['type' => 'slack', 'url' => 'https://a.example', 'insecure' => true], 'unknown option "insecure"'],
    [['type' => 'mail', 'to' => []], '"to"'],
    [['type' => 'mail', 'to' => ['a' => 'a@example.com']], '"to"'],
    [['type' => 'mail', 'to' => 5], '"to"'],
    [['type' => 'mail', 'to' => ['a@example.com', 'nope']], 'not valid'],
    [['type' => 'mail', 'to' => [5]], 'not valid'],
    [['type' => 'telegram', 'token' => 't', 'chat_id' => ''], 'chat_id'],
    [['type' => 'telegram', 'token' => 't', 'chat_id' => true], 'chat_id'],
    [['type' => 'ntfy', 'url' => 'https://a.example/t', 'allow_private' => 1], '"allow_private" must be'],
    [['type' => 'slack', 'url' => ''], '"url" must be a string'],
    [['type' => 'slack', 'url' => 5], '"url" must be a string'],
    [['type' => 'slack', 'url' => ['other' => 'X']], '"url" must be a string'],
    [['type' => 'slack', 'url' => ['env' => 'X', 'constant' => 'Y']], '"url" must be a string'],
    [['type' => 'slack', 'url' => ['env' => 5]], '"url" must be a string'],
    [['type' => 'slack', 'url' => ['env' => 'HORNET_MUT_UNSET']], 'environment variable "HORNET_MUT_UNSET" that holds "url" is not set'],
    [['type' => 'slack', 'url' => ['constant' => 'HORNET_MUT_UNSET']], 'constant "HORNET_MUT_UNSET"'],
]);

it('refuses an address that is not https, without repeating it', function (string $type, array $more, string $url): void {
    $build = static fn () => mutAppChannel(['type' => $type, 'url' => $url, ...$more]);

    expect($build)->toThrow(InvalidSettingsException::class, 'https address');

    try {
        $build();
    } catch (InvalidSettingsException $exception) {
        expect($exception->getMessage())->not->toContain('private-part')
            ->and(str_contains($exception->getMessage(), 'http only with'))->toBe(in_array($type, ['webhook', 'ntfy'], true));
    }
})->with([
    ['slack', [], 'http://a.example/private-part'],
    ['discord', [], 'ftp://a.example/private-part'],
    ['teams', [], 'private-part'],
    ['slack', [], 'https:///private-part'],
    ['webhook', ['secret' => 's'], 'http://a.example/private-part'],
    ['webhook', ['secret' => 's', 'insecure' => false], 'http://a.example/private-part'],
    ['ntfy', ['insecure' => true], 'ftp://a.example/private-part'],
]);

it('allows plain http only where the owner says so, and keeps what a channel was told', function (): void {
    $spec = mutAppChannel(['type' => 'webhook', 'url' => 'http://a.example/in', 'secret' => 's', 'insecure' => true, 'allow_private' => true])->notifications->channels[0];
    $telegram = mutAppChannel(['type' => 'telegram', 'token' => 't', 'chat_id' => -100])->notifications->channels[0];
    $mail = mutAppChannel(['type' => 'mail', 'to' => 'a@example.com'])->notifications->channels[0];

    expect($spec->options['url'])->toBeInstanceOf(Secret::class)
        ->and($spec->options['url']->reveal())->toBe('http://a.example/in')
        ->and($spec->options['allow_private'])->toBeTrue()
        ->and($telegram->options['chat_id'])->toBe('-100')
        ->and($mail->options['to'])->toBe(['a@example.com']);
});

it('reads a secret from the environment or a constant', function (): void {
    $secrets = new EnvironmentSecrets;
    $_ENV['HORNET_MUT_ENV'] = 'from-env';
    $_SERVER['HORNET_MUT_SERVER'] = 'from-server';
    $_ENV['HORNET_MUT_EMPTY'] = '';
    putenv('HORNET_MUT_PUT=from-put');
    defined('HORNET_MUT_CONSTANT') || define('HORNET_MUT_CONSTANT', 'from-constant');
    defined('HORNET_MUT_NUMBER') || define('HORNET_MUT_NUMBER', 5);
    defined('HORNET_MUT_BLANK') || define('HORNET_MUT_BLANK', '');

    expect([$secrets->env('HORNET_MUT_ENV'), $secrets->env('HORNET_MUT_SERVER'), $secrets->env('HORNET_MUT_PUT'), $secrets->env('HORNET_MUT_EMPTY'), $secrets->env('HORNET_MUT_ABSENT')])->toBe(['from-env', 'from-server', 'from-put', null, null])
        ->and([$secrets->constant('HORNET_MUT_CONSTANT'), $secrets->constant('HORNET_MUT_NUMBER'), $secrets->constant('HORNET_MUT_BLANK'), $secrets->constant('HORNET_MUT_ABSENT')])->toBe(['from-constant', null, null, null]);

    unset($_ENV['HORNET_MUT_ENV'], $_SERVER['HORNET_MUT_SERVER'], $_ENV['HORNET_MUT_EMPTY']);
    putenv('HORNET_MUT_PUT');
});

it('puts the settings of one site over the shared ones', function (): void {
    $config = ['enabled' => true, 'admin' => true, 'sites' => [2 => ['admin' => false], 3 => 'x']];

    expect(SettingsLoader::merge($config, 2))->toBe(['enabled' => true, 'admin' => false])
        ->and(SettingsLoader::merge($config, 1))->toBe(['enabled' => true, 'admin' => true])
        ->and(SettingsLoader::merge($config, 3))->toBe(['enabled' => true, 'admin' => true])
        ->and(SettingsLoader::merge(['sites' => 'x'], 2))->toBe([]);
});

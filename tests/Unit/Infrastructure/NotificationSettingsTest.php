<?php

declare(strict_types=1);

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\InvalidSettingsException;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Infrastructure\Config\ChannelType;
use Pollora\Hornet\Infrastructure\Config\NotificationSettings;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Tests\Fixtures\ArraySecrets;

function notificationsFrom(array $config, ?ArraySecrets $secrets = null): NotificationSettings
{
    return NotificationSettings::fromArray($config, $secrets ?? new ArraySecrets(['HORNET_SLACK' => 'https://hooks.slack.com/services/T/B/SECRET'], ['HORNET_KEY' => 'signing-key']));
}

it('has no channel by default, and runs daily', function (): void {
    $settings = Settings::fromArray([], new ArraySecrets);

    expect($settings->notifications->channels)->toBe([])
        ->and($settings->notifications->schedule)->toBe('daily')
        ->and($settings->notifications->cooldown)->toBe(86400)
        ->and($settings->notifications->rateLimit)->toBe(20);
});

it('reads a channel of every type', function (): void {
    $notifications = notificationsFrom(['channels' => [
        'ops-mail' => ['type' => 'mail', 'to' => ['ops@example.com', 'sec@example.com']],
        'slack' => ['type' => 'slack', 'url' => ['env' => 'HORNET_SLACK'], 'min_severity' => 'high'],
        'teams' => ['type' => 'teams', 'url' => 'https://example.webhook.office.com/x'],
        'discord' => ['type' => 'discord', 'url' => 'https://discord.com/api/webhooks/1/abc'],
        'telegram' => ['type' => 'telegram', 'token' => 'bot-token', 'chat_id' => -1001234],
        'ntfy' => ['type' => 'ntfy', 'url' => 'https://ntfy.sh/my-topic', 'token' => 'tk_x'],
        'siem' => ['type' => 'webhook', 'url' => 'https://siem.example.com/in', 'secret' => ['constant' => 'HORNET_KEY']],
    ]]);

    expect(array_map(static fn ($spec): string => $spec->type->value, $notifications->channels))->toBe(['mail', 'slack', 'teams', 'discord', 'telegram', 'ntfy', 'webhook'])
        ->and($notifications->channels[1]->minSeverity)->toBe(Severity::High)
        ->and($notifications->channels[0]->minSeverity)->toBe(Severity::Medium)
        ->and($notifications->channels[0]->options['to'])->toBe(['ops@example.com', 'sec@example.com'])
        ->and($notifications->channels[4]->options['chat_id'])->toBe('-1001234')
        ->and($notifications->channels[1]->options['url'])->toBeInstanceOf(Secret::class)
        ->and($notifications->channels[1]->options['url']->reveal())->toBe('https://hooks.slack.com/services/T/B/SECRET')
        ->and($notifications->channels[6]->options['secret']->reveal())->toBe('signing-key');
});

it('accepts one address as a string', function (): void {
    expect(notificationsFrom(['channels' => ['m' => ['type' => 'mail', 'to' => 'ops@example.com']]])->channels[0]->options['to'])->toBe(['ops@example.com']);
});

it('refuses what it does not understand', function (array $config, string $message): void {
    expect(fn () => notificationsFrom($config))->toThrow(InvalidSettingsException::class, $message);
})->with([
    'unknown key' => [['chanels' => []], 'chanels'],
    'bad schedule' => [['schedule' => 'weekly'], 'schedule'],
    'cooldown too small' => [['cooldown' => 5], 'cooldown'],
    'cooldown not a number' => [['cooldown' => '86400'], 'cooldown'],
    'rate limit zero' => [['rate_limit' => 0], 'rate_limit'],
    'channels not a map' => [['channels' => 'slack'], 'channels'],
    'bad channel name' => [['channels' => ['Bad Name' => ['type' => 'mail', 'to' => 'a@b.co']]], 'channel name'],
    'channel not an array' => [['channels' => ['x' => 'mail']], 'array'],
    'unknown type' => [['channels' => ['x' => ['type' => 'sms']]], 'type'],
    'bad severity' => [['channels' => ['x' => ['type' => 'mail', 'to' => 'a@b.co', 'min_severity' => 'severe']]], 'min_severity'],
    'unknown option' => [['channels' => ['x' => ['type' => 'mail', 'to' => 'a@b.co', 'cc' => 'c@d.co']]], 'unknown option "cc"'],
    'missing option' => [['channels' => ['x' => ['type' => 'slack']]], '"url" is required'],
    'bad address' => [['channels' => ['x' => ['type' => 'mail', 'to' => ['not-an-address']]]], 'not valid'],
    'empty list of addresses' => [['channels' => ['x' => ['type' => 'mail', 'to' => []]]], '"to"'],
    'http url' => [['channels' => ['x' => ['type' => 'slack', 'url' => 'http://hooks.slack.com/x']]], 'https'],
    'url without host' => [['channels' => ['x' => ['type' => 'slack', 'url' => 'https://']]], 'https'],
    'not a url' => [['channels' => ['x' => ['type' => 'discord', 'url' => 'nonsense']]], 'https'],
    'flag that is not a bool' => [['channels' => ['x' => ['type' => 'ntfy', 'url' => 'https://ntfy.sh/t', 'allow_private' => 'yes']]], 'true or false'],
    'secret of a wrong shape' => [['channels' => ['x' => ['type' => 'slack', 'url' => ['file' => '/etc/x']]]], 'must be a string'],
    'env variable not set' => [['channels' => ['x' => ['type' => 'slack', 'url' => ['env' => 'NOPE']]]], 'environment variable "NOPE"'],
    'constant not set' => [['channels' => ['x' => ['type' => 'webhook', 'url' => 'https://a.co/x', 'secret' => ['constant' => 'NOPE']]]], 'constant "NOPE"'],
    'chat id of a wrong type' => [['channels' => ['x' => ['type' => 'telegram', 'token' => 't', 'chat_id' => []]]], 'chat_id'],
]);

it('allows plain http only for a webhook or ntfy that says so', function (): void {
    $ok = notificationsFrom(['channels' => ['siem' => ['type' => 'webhook', 'url' => 'http://10.0.0.5/in', 'secret' => 's', 'insecure' => true, 'allow_private' => true]]]);

    expect($ok->channels[0]->options['insecure'])->toBeTrue();
    expect(fn () => notificationsFrom(['channels' => ['siem' => ['type' => 'webhook', 'url' => 'http://10.0.0.5/in', 'secret' => 's']]]))->toThrow(InvalidSettingsException::class, 'insecure');
    expect(fn () => notificationsFrom(['channels' => ['s' => ['type' => 'slack', 'url' => 'http://hooks.slack.com/x', 'insecure' => true]]]))->toThrow(InvalidSettingsException::class, 'unknown option "insecure"');
});

it('never repeats a secret in an error', function (): void {
    foreach ([
        ['type' => 'slack', 'url' => 'http://hooks.slack.com/services/TOPSECRETVALUE'],
        ['type' => 'webhook', 'url' => 'https://a.co/x', 'secret' => 'TOPSECRETVALUE', 'insecure' => 'TOPSECRETVALUE'],
        ['type' => 'telegram', 'token' => 'TOPSECRETVALUE', 'chat_id' => []],
    ] as $channel) {
        try {
            notificationsFrom(['channels' => ['x' => $channel]]);
        } catch (InvalidSettingsException $error) {
            expect($error->getMessage())->not->toContain('TOPSECRETVALUE');
        }
    }
});

it('lists the types and what they need', function (): void {
    foreach (ChannelType::cases() as $type) {
        expect($type->options())->not->toBe([]);
    }
});

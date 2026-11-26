<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Config;

/**
 * The kinds of channel, and what each one needs to be told in the settings.
 *
 * @internal
 */
enum ChannelType: string
{
    case Mail = 'mail';
    case Webhook = 'webhook';
    case Slack = 'slack';
    case Teams = 'teams';
    case Discord = 'discord';
    case Telegram = 'telegram';
    case Ntfy = 'ntfy';

    /**
     * The options the channel takes, by name: `secret` ones are read as secrets, `bool` ones are flags.
     *
     * @return array<string, array{kind: string, required: bool}>
     */
    public function options(): array
    {
        return match ($this) {
            self::Mail => ['to' => ['kind' => 'emails', 'required' => true]],
            self::Webhook => ['url' => ['kind' => 'secret-url', 'required' => true], 'secret' => ['kind' => 'secret', 'required' => true], 'allow_private' => ['kind' => 'bool', 'required' => false], 'insecure' => ['kind' => 'bool', 'required' => false]],
            self::Slack, self::Teams, self::Discord => ['url' => ['kind' => 'secret-url', 'required' => true]],
            self::Telegram => ['token' => ['kind' => 'secret', 'required' => true], 'chat_id' => ['kind' => 'chat', 'required' => true]],
            self::Ntfy => ['url' => ['kind' => 'secret-url', 'required' => true], 'token' => ['kind' => 'secret', 'required' => false], 'allow_private' => ['kind' => 'bool', 'required' => false], 'insecure' => ['kind' => 'bool', 'required' => false]],
        };
    }
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Notification;

use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Notification\Channel;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\Mailer;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Infrastructure\Config\ChannelSpec;
use Pollora\Hornet\Infrastructure\Config\ChannelType;
use Pollora\Hornet\Infrastructure\Notification\Channels\DiscordChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\MailChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\NtfyChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\SlackChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\TeamsChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\TelegramChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\WebhookChannel;

/**
 * Builds the channels the settings describe. The settings are already checked: every option a channel needs is there.
 *
 * @internal
 */
final readonly class ChannelFactory
{
    public function __construct(
        private HttpClient $http,
        private Mailer $mailer,
        private Clock $clock,
    ) {}

    /**
     * @param  list<ChannelSpec>  $specs
     * @return list<Channel>
     */
    public function build(array $specs): array
    {
        return array_map($this->channel(...), $specs);
    }

    public function channel(ChannelSpec $spec): Channel
    {
        $options = $spec->options;
        $private = ($options['allow_private'] ?? false) === true;

        return match ($spec->type) {
            ChannelType::Mail => new MailChannel($spec->name, $spec->minSeverity, $this->mailer, self::list($options['to'] ?? [])),
            ChannelType::Slack => new SlackChannel($spec->name, $spec->minSeverity, $this->http, self::secret($options['url'] ?? null)),
            ChannelType::Teams => new TeamsChannel($spec->name, $spec->minSeverity, $this->http, self::secret($options['url'] ?? null)),
            ChannelType::Discord => new DiscordChannel($spec->name, $spec->minSeverity, $this->http, self::secret($options['url'] ?? null)),
            ChannelType::Telegram => new TelegramChannel($spec->name, $spec->minSeverity, $this->http, self::secret($options['token'] ?? null), (string) ($options['chat_id'] ?? '')),
            ChannelType::Ntfy => new NtfyChannel($spec->name, $spec->minSeverity, $this->http, self::secret($options['url'] ?? null), ($options['token'] ?? null) instanceof Secret ? $options['token'] : null, $private),
            ChannelType::Webhook => new WebhookChannel($spec->name, $spec->minSeverity, $this->http, self::secret($options['url'] ?? null), self::secret($options['secret'] ?? null), $this->clock, $private),
        };
    }

    private static function secret(mixed $value): Secret
    {
        return $value instanceof Secret ? $value : Secret::of('');
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}

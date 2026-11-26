<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Notification\Channels;

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Infrastructure\Notification\Text;

/**
 * ntfy, through its JSON API: one POST to the server's root, with the topic in the body.
 *
 * Only the fields `topic`, `title`, `message`, `priority` and `tags` are sent, as plain text (Markdown stays off): the headers and
 * fields that open a link, attach a file, send an e-mail or place a call are never fed with text from a site.
 * A message holds 4096 bytes; a longer one would become an attachment, so it is cut below that.
 * A private-server token goes in `Authorization: Bearer`; a topic on an open server is a secret too, so the whole URL is kept secret.
 *
 * @internal
 */
final class NtfyChannel extends HttpChannel
{
    private const int MESSAGE_BYTES = 3900;

    private const int TITLE_BYTES = 250;

    public function __construct(
        string $name,
        Severity $minSeverity,
        HttpClient $http,
        Secret $topicUrl,
        private readonly ?Secret $token,
        bool $allowPrivate,
    ) {
        parent::__construct($name, $minSeverity, $http, self::serverRoot($topicUrl), $allowPrivate);
        $this->topic = self::topicOf($topicUrl);
    }

    private readonly string $topic;

    protected function request(Notification $notification): array
    {
        $severity = $notification->severity();
        $headers = ['Content-Type' => 'application/json'];

        if ($this->token instanceof Secret) {
            $headers['Authorization'] = 'Bearer '.$this->token->reveal();
        }

        $payload = [
            'topic' => $this->topic,
            'title' => Text::truncateBytes(Text::clean($notification->subject()), self::TITLE_BYTES),
            'message' => Text::truncateBytes(implode("\n", [...Text::lines($notification, 250), Text::clean($notification->siteUrl)]), self::MESSAGE_BYTES),
            'priority' => match ($severity) {
                Severity::Critical => 5,
                Severity::High => 4,
                Severity::Medium => 3,
                Severity::Low => 2,
                Severity::Info => 1,
            },
            'tags' => [$severity->atLeast(Severity::High) ? 'rotating_light' : 'warning'],
        ];

        return [$headers, $this->json($payload)];
    }

    /**
     * `https://ntfy.sh/alerts` and `https://example.com/ntfy/alerts` both post to the server root (`https://ntfy.sh/`, `https://example.com/ntfy/`).
     */
    private static function serverRoot(Secret $topicUrl): Secret
    {
        $parts = parse_url($topicUrl->reveal());
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        $prefix = substr($path, 0, (int) strrpos($path, '/') + 1);

        return Secret::of(($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '').($prefix === '' ? '/' : $prefix));
    }

    private static function topicOf(Secret $topicUrl): string
    {
        $path = (string) (parse_url($topicUrl->reveal(), PHP_URL_PATH) ?? '');

        return basename($path);
    }
}

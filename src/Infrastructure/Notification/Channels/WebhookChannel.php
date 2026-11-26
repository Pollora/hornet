<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\Notification\Channels;

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\Notification;
use Pollora\Hornet\Domain\Notification\Secret;

/**
 * A generic webhook: JSON, signed, for a receiver you write (a SIEM, a ticket system, a bridge).
 *
 * Contract (version 1):
 *
 *     POST <url>
 *     Content-Type: application/json
 *     X-Hornet-Id: <id of the message: the same on every retry, use it to deduplicate>
 *     X-Hornet-Timestamp: <unix time>
 *     X-Hornet-Signature: sha256=<hex of HMAC-SHA256(secret, "<timestamp>.<raw body>")>
 *
 *     {"version":1,"event":"hornet.alert","id":"…","sent_at":"…","site":{"name":"…","url":"…"},"severity":"high",
 *      "alerts":[{"key":"…","severity":"…","title":"…","details":["…"],"fix":null}]}
 *
 * The receiver must compare the signature in constant time, and refuse a timestamp more than five minutes old.
 *
 * @internal
 */
final class WebhookChannel extends HttpChannel
{
    public function __construct(
        string $name,
        Severity $minSeverity,
        HttpClient $http,
        Secret $url,
        private readonly Secret $signingKey,
        private readonly Clock $clock,
        bool $allowPrivate,
    ) {
        parent::__construct($name, $minSeverity, $http, $url, $allowPrivate);
    }

    protected function request(Notification $notification): array
    {
        $body = $this->json([
            'version' => 1,
            'event' => 'hornet.alert',
            'id' => $notification->id,
            'sent_at' => $this->clock->now()->format(DATE_ATOM),
            'site' => ['name' => $notification->siteName, 'url' => $notification->siteUrl],
            'severity' => $notification->severity()->value,
            'alerts' => array_map(static fn ($alert): array => $alert->toArray(), $notification->alerts),
        ]);

        $timestamp = (string) $this->clock->now()->getTimestamp();

        return [
            [
                'Content-Type' => 'application/json',
                'X-Hornet-Id' => $notification->id,
                'X-Hornet-Timestamp' => $timestamp,
                'X-Hornet-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $this->signingKey->reveal()),
            ],
            $body,
        ];
    }
}

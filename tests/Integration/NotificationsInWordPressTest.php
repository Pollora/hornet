<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Notification\ChannelStatus;
use Pollora\Hornet\Application\Notification\NotificationService;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\QueuedDelivery;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\Notification\NotificationFactory;
use Pollora\Hornet\Infrastructure\WordPress\NotificationModule;
use Pollora\Hornet\Infrastructure\WordPress\OptionNotificationState;
use Pollora\Hornet\Infrastructure\WordPress\OptionRetryQueue;
use Pollora\Hornet\Infrastructure\WordPress\OptionRunLock;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Pollora\Hornet\Infrastructure\WordPress\WordPressHooks;
use Pollora\Hornet\Infrastructure\WordPress\WpHttpClient;
use Pollora\Hornet\Infrastructure\WordPress\WpMailer;
use Pollora\Hornet\Tests\Fixtures\Samples;
use Pollora\Hornet\Tests\Integration\Receiver;

beforeAll(function (): void {
    $GLOBALS['hornet_receiver'] = Receiver::start();
});

afterAll(function (): void {
    $GLOBALS['hornet_receiver']->stop();
});

beforeEach(function (): void {
    $GLOBALS['hornet_receiver']->reset();
});

afterEach(function (): void {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'hornet\\_%'");
    wp_cache_flush();
    wp_clear_scheduled_hook(NotificationModule::AUDIT_HOOK);
    wp_clear_scheduled_hook(NotificationModule::RETRY_HOOK);
    remove_all_filters('pre_wp_mail');
});

function receiver(): Receiver
{
    return $GLOBALS['hornet_receiver'];
}

/**
 * @param  array<string, mixed>  $channel
 */
function serviceWith(array $channel): NotificationService
{
    $settings = Settings::fromArray(['notifications' => ['channels' => ['siem' => $channel]]]);

    return NotificationFactory::forWordPress()->service($settings, new WordPressEnvironment, 'Test site', 'https://example.test');
}

describe('state', function (): void {
    it('remembers what a channel was told, and forgets it', function (): void {
        $state = new OptionNotificationState;
        $at = new DateTimeImmutable('2026-11-25 10:00:00 UTC');

        expect($state->lastTold('siem', 'a'))->toBeNull();

        $state->remember('siem', ['a', 'b'], $at);

        expect($state->lastTold('siem', 'a')?->getTimestamp())->toBe($at->getTimestamp())
            ->and($state->lastTold('other', 'a'))->toBeNull();

        $state->forget('siem', ['a']);

        expect($state->lastTold('siem', 'a'))->toBeNull()->and($state->lastTold('siem', 'b'))->not->toBeNull();
    });

    it('counts the messages of the last hour and drops the old ones', function (): void {
        $state = new OptionNotificationState;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $state->recordSent('siem', $now->modify('-2 hours'));
        $state->recordSent('siem', $now->modify('-10 minutes'));
        $state->recordSent('siem', $now);

        expect($state->sentSince('siem', $now->modify('-1 hour')))->toBe(2);
    });

    it('is not loaded on every page view', function (): void {
        (new OptionNotificationState)->remember('siem', ['a'], new DateTimeImmutable);

        expect(array_key_exists('hornet_notify_state', wp_load_alloptions()))->toBeFalse();
    });
});

describe('queue', function (): void {
    function queued(string $id = 'abcdef0123456789', int $dueIn = -10, int $attempts = 1): QueuedDelivery
    {
        return new QueuedDelivery($id, 'siem', Samples::notification(), $attempts, (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($dueIn.' seconds'));
    }

    it('keeps a message until it is due, then hands it back whole', function (): void {
        $queue = new OptionRetryQueue;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $queue->push(queued('aaaaaaaaaaaaaaaa', -10));
        $queue->push(queued('bbbbbbbbbbbbbbbb', 3600));

        $due = $queue->due($now);

        expect($queue->count())->toBe(2)->and($due)->toHaveCount(1)->and($due[0]->id)->toBe('aaaaaaaaaaaaaaaa')
            ->and($due[0]->channel)->toBe('siem')->and($due[0]->attempts)->toBe(1)
            ->and($due[0]->notification->toArray())->toBe(Samples::notification()->toArray());

        $queue->remove('aaaaaaaaaaaaaaaa');

        expect($queue->count())->toBe(1);
    });

    it('replaces a message that is pushed again, and refuses an id that is not its own', function (): void {
        $queue = new OptionRetryQueue;
        $queue->push(queued('aaaaaaaaaaaaaaaa', -10, 1));
        $queue->push(queued('aaaaaaaaaaaaaaaa', -10, 2));
        $queue->push(queued('../../evil', -10));
        $queue->push(queued('AAAA', -10));
        $queue->remove('not-an-id');

        expect($queue->count())->toBe(1)->and($queue->due(new DateTimeImmutable)[0]->attempts)->toBe(2);
    });

    it('stops growing at a hundred messages', function (): void {
        $queue = new OptionRetryQueue;

        for ($i = 0; $i < 105; $i++) {
            $queue->push(queued(sprintf('%016x', $i)));
        }

        expect($queue->count())->toBe(100);

        // A message already in the queue can still be updated when it is full
        $queue->push(queued(sprintf('%016x', 3), -10, 4));

        expect($queue->count())->toBe(100);
    });

    it('throws away a damaged row instead of failing on it', function (): void {
        update_option('hornet_nq_0123456789abcdef', 'not json', false);
        update_option('hornet_nq_fedcba9876543210', '{"channel":1}', false);

        expect((new OptionRetryQueue)->due(new DateTimeImmutable))->toBe([])->and(get_option('hornet_nq_0123456789abcdef'))->toBeFalse();
    });
});

describe('lock', function (): void {
    it('lets one run in at a time', function (): void {
        $lock = new OptionRunLock;

        expect($lock->acquire())->toBeTrue()->and((new OptionRunLock)->acquire())->toBeFalse();

        $lock->release();

        expect((new OptionRunLock)->acquire())->toBeTrue();
    });

    it('takes over the lock of a run that died', function (): void {
        update_option('hornet_notify_lock', time() - 3600, false);

        expect((new OptionRunLock)->acquire())->toBeTrue();
    });
});

describe('mail', function (): void {
    it('goes through wp_mail, as plain text, to every address', function (): void {
        $sent = null;
        add_filter('pre_wp_mail', static function ($short, array $args) use (&$sent): bool {
            $sent = $args;

            return true;
        }, 10, 2);

        $ok = (new WpMailer)->send(['a@example.com', 'b@example.com'], "Subject\r\nBcc: evil@example.com", 'Body');

        expect($ok)->toBeTrue()->and($sent['to'])->toBe(['a@example.com', 'b@example.com'])->and($sent['subject'])->toBe('Subject Bcc: evil@example.com')
            ->and($sent['headers'])->toBe(['Content-Type: text/plain; charset=UTF-8']);
    });

    it('says no when wp_mail says no', function (): void {
        add_filter('pre_wp_mail', '__return_false');

        expect((new WpMailer)->send(['a@example.com'], 'S', 'B'))->toBeFalse();
    });
});

describe('http client', function (): void {
    it('refuses a private address unless the owner allowed it, and never names the address', function (): void {
        $client = new WpHttpClient;
        $secret = Secret::of(receiver()->url('/status/200?token=TOPSECRET'));

        try {
            $client->post($secret, [], '{}');
            $message = 'no exception';
        } catch (TransportException $error) {
            $message = $error->getMessage();
        }

        expect($message)->toStartWith('The request failed')->not->toContain('TOPSECRET')->not->toContain('127.0.0.1')
            ->and(receiver()->requests())->toBe([]);
    });

    it('asks a private address when the site is asking itself, and refuses it otherwise', function (): void {
        $allowed = (new WpHttpClient)->get(Secret::of(receiver()->url('/status/200')), [], 100, 5, true);
        $refused = null;

        try {
            (new WpHttpClient)->get(Secret::of(receiver()->url('/status/200')), [], 100);
        } catch (TransportException $error) {
            $refused = $error->getMessage();
        }

        expect($allowed->status)->toBe(200)->and($refused)->toStartWith('The request failed');
    });

    it('posts to a private address the owner allowed, with the body and headers', function (): void {
        $response = (new WpHttpClient)->post(Secret::of(receiver()->url('/status/200')), ['Content-Type' => 'application/json', 'X-Test' => 'yes'], '{"a":1}', true);
        $request = receiver()->requests()[0];

        expect($response->status)->toBe(200)->and($request['method'])->toBe('POST')->and($request['body'])->toBe('{"a":1}')
            ->and($request['headers']['X-Test'])->toBe('yes')->and($request['headers']['User-Agent'])->not->toContain('example');
    });

    it('reads the status, the wait it was asked for and the start of the body', function (): void {
        $response = (new WpHttpClient)->post(Secret::of(receiver()->url('/status/429')), [], '{}', true);

        expect($response->status)->toBe(429)->and($response->retryAfter)->toBe(7)->and($response->body)->toBe('{"ok":false}');
    });

    it('does not follow a redirect: an alert goes where the owner said and nowhere else', function (): void {
        $response = (new WpHttpClient)->post(Secret::of(receiver()->url('/redirect')), [], '{}', true);

        expect($response->status)->toBe(302)->and(array_column(receiver()->requests(), 'path'))->toBe(['/redirect']);
    });

    it('reports an unreachable server as an error that can pass', function (): void {
        expect(fn () => (new WpHttpClient)->post(Secret::of('http://127.0.0.1:9/x'), [], '{}', true))->toThrow(TransportException::class, 'The request failed');
    });
});

describe('end to end', function (): void {
    function webhook(string $path = '/status/200'): array
    {
        return ['type' => 'webhook', 'url' => receiver()->url($path), 'secret' => 'the-signing-key', 'insecure' => true, 'allow_private' => true, 'min_severity' => 'low'];
    }

    it('audits the real site and posts a signed message the receiver can verify', function (): void {
        $run = serviceWith(webhook())->run();
        $request = receiver()->requests()[0];
        $timestamp = $request['headers']['X-Hornet-Timestamp'];
        $json = json_decode($request['body'], true);

        expect($run->outcomes[0]->status)->toBe(ChannelStatus::Sent)
            ->and($request['headers']['X-Hornet-Signature'])->toBe('sha256='.hash_hmac('sha256', $timestamp.'.'.$request['body'], 'the-signing-key'))
            ->and(abs((int) $timestamp - time()))->toBeLessThan(60)
            ->and($json['version'])->toBe(1)->and($json['site'])->toBe(['name' => 'Test site', 'url' => 'https://example.test'])
            ->and($json['alerts'])->not->toBe([])
            ->and($request['headers']['X-Hornet-Id'])->toBe($json['id']);
    });

    it('never leaks a key, a salt or the signing key in what it sends', function (): void {
        serviceWith(webhook())->run();
        $body = receiver()->requests()[0]['body'];

        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'DB_PASSWORD'] as $name) {
            if ((string) constant($name) !== '') {
                expect($body)->not->toContain((string) constant($name));
            }
        }

        expect($body)->not->toContain('the-signing-key');
    });

    it('tells the same news once, then nothing, and again when the state is cleared', function (): void {
        serviceWith(webhook())->run();
        $second = serviceWith(webhook())->run();

        expect(receiver()->requests())->toHaveCount(1)->and($second->outcomes[0]->status)->toBe(ChannelStatus::Nothing);

        delete_option('hornet_notify_state');
        serviceWith(webhook())->run();

        expect(receiver()->requests())->toHaveCount(2);
    });

    it('queues a message when the receiver is down, and sends it when its time comes', function (): void {
        $down = serviceWith(webhook('/status/503'))->run();

        expect($down->outcomes[0]->status)->toBe(ChannelStatus::Queued)->and((new OptionRetryQueue)->count())->toBe(1);

        // Bring the due time into the past, and point the channel at a receiver that works
        global $wpdb;
        $name = $wpdb->get_var("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'hornet\\_nq\\_%'");
        $data = json_decode((string) get_option($name), true);
        $data['due'] = time() - 5;
        update_option($name, (string) wp_json_encode($data), false);

        $retried = serviceWith(webhook('/status/200'))->retry();

        expect($retried[0]->status)->toBe(ChannelStatus::Sent)->and((new OptionRetryQueue)->count())->toBe(0)
            ->and(array_column(receiver()->requests(), 'path'))->toBe(['/status/503', '/status/200']);
    });

    it('does not repeat a message that was refused for good, and does not queue it', function (): void {
        $refused = serviceWith(webhook('/status/404'))->run();

        expect($refused->outcomes[0]->status)->toBe(ChannelStatus::Failed)->and($refused->outcomes[0]->reason)->toContain('HTTP 404')->and((new OptionRetryQueue)->count())->toBe(0);
    });

    it('sends a test message to a channel', function (): void {
        $outcome = serviceWith(webhook())->test('siem');
        $json = json_decode(receiver()->requests()[0]['body'], true);

        expect($outcome->status)->toBe(ChannelStatus::Sent)->and($json['alerts'][0]['title'])->toContain('test message');
    });

    it('lets one run in at a time, for real', function (): void {
        (new OptionRunLock)->acquire();
        $run = serviceWith(webhook())->run();

        expect($run->report)->toBeNull()->and(receiver()->requests())->toBe([]);
    });

    it('sends mail through wp_mail', function (): void {
        $sent = [];
        add_filter('pre_wp_mail', static function ($short, array $args) use (&$sent): bool {
            $sent[] = $args;

            return true;
        }, 10, 2);

        $settings = Settings::fromArray(['notifications' => ['channels' => ['ops' => ['type' => 'mail', 'to' => 'ops@example.com', 'min_severity' => 'low']]]]);
        NotificationFactory::forWordPress()->service($settings, new WordPressEnvironment, 'Test site', 'https://example.test')->run();

        expect($sent)->toHaveCount(1)->and($sent[0]['subject'])->toStartWith('[Hornet] Test site')->and($sent[0]['to'])->toBe(['ops@example.com']);
    });
});

describe('cron', function (): void {
    it('schedules the audit, moves it and takes it out', function (): void {
        $module = fn (string $schedule): NotificationModule => new NotificationModule(static fn () => throw new LogicException('not built'), $schedule);

        $module('daily')->plan();

        expect(wp_get_schedule(NotificationModule::AUDIT_HOOK))->toBe('daily')
            ->and(wp_next_scheduled(NotificationModule::AUDIT_HOOK))->toBeGreaterThan(time() + 200);

        $module('hourly')->plan();

        expect(wp_get_schedule(NotificationModule::AUDIT_HOOK))->toBe('hourly');

        $module('off')->plan();

        expect(wp_next_scheduled(NotificationModule::AUDIT_HOOK))->toBeFalse();
    });

    it('runs the audit when WordPress fires the hook', function (): void {
        $service = serviceWith(webhook());
        (new NotificationModule(static fn () => $service, 'daily'))->register(new WordPressHooks);

        do_action(NotificationModule::AUDIT_HOOK);

        expect(receiver()->requests())->toHaveCount(1);
        remove_all_actions(NotificationModule::AUDIT_HOOK);
        remove_all_actions(NotificationModule::RETRY_HOOK);
    });

    it('plans a retry when a message waits', function (): void {
        $service = serviceWith(webhook('/status/503'));
        (new NotificationModule(static fn () => $service, 'daily'))->audit();

        expect(wp_next_scheduled(NotificationModule::RETRY_HOOK))->toBeGreaterThan(time() + 200);
    });
});

it('has a severity to filter on', function (): void {
    expect(Severity::Low->atLeast(Severity::Info))->toBeTrue();
});

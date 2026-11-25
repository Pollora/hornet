<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Admin;

use Closure;
use Pollora\Hornet\Application\Scan\ScanJob;
use Pollora\Hornet\Domain\Scan\ScanState;
use Pollora\Hornet\Infrastructure\WordPress\HookRegistrar;
use Pollora\Hornet\Infrastructure\WordPress\Module;
use Pollora\Hornet\Infrastructure\WordPress\ScanModule;
use WP_REST_Request;
use WP_REST_Response;

/**
 * What the screen asks of the server: where the site stands, to start a scan, to send a test message to a channel.
 *
 * Every route is for the people who may see the screen, and the REST cookie authentication asks for the nonce of the session.
 * Nothing here changes a setting: a protection is switched on or off in the settings file, not from a screen.
 */
final readonly class AdminRestModule implements Module
{
    public const string NAMESPACE = 'hornet/v1';

    /**
     * @param  Closure(): array<string, mixed>  $status  built when it is asked for: the audit runs, and it is not cheap
     * @param  Closure(string): ?array{status: string, reason: string}  $test  sends a test message to a channel; null when the channel does not exist
     */
    public function __construct(
        private Closure $status,
        private ScanJob $job,
        private Closure $test,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction('rest_api_init', $this->routes(...));
        // A security product does not announce itself to whoever asks for /wp-json/
        $hooks->addFilter('rest_index', $this->hideFromIndex(...));
    }

    /**
     * Takes the namespace out of the index of the REST API, for anyone who may not use the routes.
     */
    public function hideFromIndex(mixed $response): mixed
    {
        if (! $response instanceof WP_REST_Response || AdminAccess::allowed()) {
            return $response;
        }

        $data = $response->get_data();

        if (is_array($data) && is_array($data['namespaces'] ?? null)) {
            $data['namespaces'] = array_values(array_diff($data['namespaces'], [self::NAMESPACE]));
            $response->set_data($data);
        }

        return $response;
    }

    public function routes(): void
    {
        $allowed = static fn (): bool => AdminAccess::allowed();

        register_rest_route(self::NAMESPACE, '/status', ['methods' => 'GET', 'callback' => $this->status(...), 'permission_callback' => $allowed, 'show_in_index' => false]);
        register_rest_route(self::NAMESPACE, '/scan', [
            ['methods' => 'GET', 'callback' => $this->scanState(...), 'permission_callback' => $allowed, 'show_in_index' => false],
            ['methods' => 'POST', 'callback' => $this->scan(...), 'permission_callback' => $allowed, 'show_in_index' => false],
        ]);
        register_rest_route(self::NAMESPACE, '/notify/test', [
            'methods' => 'POST',
            'callback' => $this->test(...),
            'permission_callback' => $allowed,
            'show_in_index' => false,
            'args' => ['channel' => ['required' => true, 'type' => 'string', 'validate_callback' => static fn (mixed $value): bool => is_string($value) && preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $value) === 1]],
        ]);
    }

    public function status(): WP_REST_Response
    {
        return new WP_REST_Response(($this->status)());
    }

    public function scanState(): WP_REST_Response
    {
        return new WP_REST_Response($this->job->state()->toArray());
    }

    /**
     * Asks for a scan, and does not wait for it: it takes minutes, and a request does not. The cron runs it, and the screen asks how it goes.
     */
    public function scan(): WP_REST_Response
    {
        if ($this->job->state()->isActive(time())) {
            return new WP_REST_Response(['queued' => false, 'reason' => 'A scan is already in progress.', 'state' => $this->job->state()->toArray()], 409);
        }

        $this->job->queued();
        wp_schedule_single_event(time(), ScanModule::NOW_HOOK);
        // The cron is started now rather than at the next visit; with DISABLE_WP_CRON the system cron picks the event up
        spawn_cron();

        return new WP_REST_Response(['queued' => true, 'state' => ScanState::fromArray($this->job->state()->toArray())->toArray()], 202);
    }

    public function test(WP_REST_Request $request): WP_REST_Response
    {
        $channel = $request->get_param('channel');
        $result = is_string($channel) ? ($this->test)($channel) : null;

        return $result === null
            ? new WP_REST_Response(['status' => 'unknown', 'reason' => 'There is no such channel.'], 404)
            : new WP_REST_Response($result);
    }
}

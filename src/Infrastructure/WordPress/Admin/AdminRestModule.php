<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Admin;

use Pollora\Hornet\Domain\Exceptions\SetAsideException;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\Journal\EventTypes;
use Pollora\Hornet\Domain\Journal\Source;
use Pollora\Hornet\Infrastructure\WordPress\HookRegistrar;
use Pollora\Hornet\Infrastructure\WordPress\I18n\WpTranslator;
use Pollora\Hornet\Infrastructure\WordPress\Module;
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

    public function __construct(
        private AdminBackend $backend,
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
        register_rest_route(self::NAMESPACE, '/journal', [
            'methods' => 'GET',
            'callback' => $this->journal(...),
            'permission_callback' => $allowed,
            'show_in_index' => false,
            'args' => [
                'type' => ['type' => 'string', 'default' => '', 'validate_callback' => static fn (mixed $value): bool => $value === '' || (is_string($value) && (EventTypes::exists($value) || in_array(rtrim($value, '.'), EventTypes::groups(), true) && str_ends_with($value, '.')))],
                'source' => ['type' => 'string', 'default' => '', 'validate_callback' => static fn (mixed $value): bool => $value === '' || (is_string($value) && Source::tryFrom($value) instanceof Source)],
                'user' => ['type' => 'integer', 'default' => 0, 'minimum' => 0],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'per_page' => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/findings/(?P<check>[a-z0-9][a-z0-9-]{0,59})/ignore', [
            [
                'methods' => 'POST',
                'callback' => $this->setAside(...),
                'permission_callback' => $allowed,
                'show_in_index' => false,
                'args' => ['reason' => ['required' => true, 'type' => 'string']],
            ],
            ['methods' => 'DELETE', 'callback' => $this->restore(...), 'permission_callback' => $allowed, 'show_in_index' => false],
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
        return new WP_REST_Response($this->backend->status());
    }

    public function journal(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->backend->journal([
            'type' => (string) $request->get_param('type'),
            'source' => (string) $request->get_param('source'),
            'user' => (int) $request->get_param('user'),
            'page' => (int) $request->get_param('page'),
            'per_page' => (int) $request->get_param('per_page'),
        ]));
    }

    /**
     * Sets a finding aside, on a reason that the person wrote.
     */
    public function setAside(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $aside = $this->backend->setAside((string) $request->get_param('check'), (string) $request->get_param('reason'));
        } catch (SetAsideException $setAsideException) {
            return self::refused($setAsideException);
        }

        return new WP_REST_Response(['ok' => true, 'aside' => $aside->toArray()]);
    }

    public function restore(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $this->backend->restore((string) $request->get_param('check'));
        } catch (SetAsideException $setAsideException) {
            return self::refused($setAsideException);
        }

        return new WP_REST_Response(['ok' => true]);
    }

    private static function refused(SetAsideException $exception): WP_REST_Response
    {
        return new WP_REST_Response(['ok' => false, 'message' => $exception->say->render(WpTranslator::forPackage()), 'kind' => $exception->kind], match ($exception->kind) {
            SetAsideException::UNKNOWN => 404,
            SetAsideException::REASON => 422,
            default => 409,
        });
    }

    public function scanState(): WP_REST_Response
    {
        return new WP_REST_Response($this->backend->state()->toArray());
    }

    /**
     * Asks for a scan, and does not wait for it: it takes minutes, and a request does not. The cron runs it, and the screen asks how it goes.
     */
    public function scan(): WP_REST_Response
    {
        $result = $this->backend->requestScan();

        return new WP_REST_Response($result, $result['queued'] ? 202 : 409);
    }

    public function test(WP_REST_Request $request): WP_REST_Response
    {
        $channel = $request->get_param('channel');
        $result = is_string($channel) ? $this->backend->testChannel($channel) : null;

        return $result === null
            ? new WP_REST_Response(['status' => 'unknown', 'reason' => Message::of('There is no such channel.')->render(WpTranslator::forPackage())], 404)
            : new WP_REST_Response($result);
    }
}

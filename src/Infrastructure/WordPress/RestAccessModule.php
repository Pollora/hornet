<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Domain\Http\RestAllowList;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\I18n\Translator;
use WP_Error;
use WP_REST_Response;

/**
 * Closes the REST API to visitors who are not logged in, but for the routes of the allow list.
 *
 * It decides on `rest_authentication_errors`, which only runs for a request received over HTTP: the `rest_do_request()` calls a theme or
 * a plugin makes while it renders a page for a visitor are not touched. It runs last, once WordPress has settled who is asking (an
 * application password, a cookie with its nonce, a token of another plugin); an error already raised is left as it is.
 *
 * Two things stay open on purpose:
 *
 * - the index (`/wp-json/`), because a client reads there where to ask for an application password. Its routes and namespaces are taken out;
 * - the CORS preflight (`OPTIONS`), which a browser sends without credentials before the request that has them. It is answered empty:
 *   no schema of a closed route is given. A request that names another method (`_method`, `X-HTTP-Method-Override`) is not a preflight.
 *
 * @internal
 */
final class RestAccessModule implements Module
{
    /** Whether the request being served over HTTP is a visitor's. Internal calls never set it. */
    private bool $visitor = false;

    /** Whether that request is the preflight of a closed route. */
    private bool $preflight = false;

    public function __construct(
        private readonly RestAllowList $allowed,
        private readonly ?Translator $translator = null,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        // After rest_application_password_check_errors (90) and rest_cookie_check_errors (100), and after whoever could undo the refusal
        $hooks->addFilter('rest_authentication_errors', $this->guard(...), PHP_INT_MAX);
        $hooks->addFilter('rest_index', $this->index(...));
        // Before rest_handle_options_request (10), which would describe the route
        $hooks->addFilter('rest_pre_dispatch', $this->answerPreflight(...), 9);
    }

    /**
     * @param  mixed  $result  an error of an earlier check, true or null. WordPress says true to an anonymous request without a nonce, so only who is logged in counts
     */
    public function guard(mixed $result): mixed
    {
        $this->visitor = false;
        $this->preflight = false;

        if ($result instanceof WP_Error || is_user_logged_in()) {
            return $result;
        }

        $this->visitor = true;
        $route = self::route();

        if ($route !== null && $this->allowed->allows($route)) {
            return $result;
        }

        if (self::isPreflight()) {
            $this->preflight = true;

            return $result;
        }

        return new WP_Error('rest_not_logged_in', Message::of('The REST API of this site is closed to visitors who are not logged in.')->render($this->translator), ['status' => 401]);
    }

    public function index(mixed $response): mixed
    {
        if (! $this->visitor || ! $response instanceof WP_REST_Response) {
            return $response;
        }

        $data = $response->get_data();

        if (is_array($data)) {
            $namespaces = is_array($data['namespaces'] ?? null) ? $data['namespaces'] : [];
            $data['namespaces'] = array_values(array_filter($namespaces, fn (mixed $one): bool => is_string($one) && $this->allowed->opens($one)));
            $data['routes'] = [];
            $response->set_data($data);
        }

        return $response;
    }

    public function answerPreflight(mixed $result): mixed
    {
        return $this->preflight && empty($result) ? new WP_REST_Response([]) : $result;
    }

    /**
     * The route as `rest_api_loaded()` hands it to the server. Null when there is none to read: the request is then refused.
     */
    private static function route(): ?string
    {
        $wp = $GLOBALS['wp'] ?? null;
        $route = is_object($wp) && isset($wp->query_vars['rest_route']) ? $wp->query_vars['rest_route'] : null;

        if (! is_string($route) || $route === '') {
            return null;
        }

        $route = rtrim($route, '/\\');

        return $route === '' ? '/' : $route;
    }

    /**
     * A real OPTIONS: the method WordPress will dispatch is the one of `_method` or of the override header when there is one.
     */
    private static function isPreflight(): bool
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? null;

        return is_string($method) && strtoupper($method) === 'OPTIONS' && ! isset($_GET['_method']) && ! isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
    }
}

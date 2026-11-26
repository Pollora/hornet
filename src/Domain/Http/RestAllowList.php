<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Http;

/**
 * The REST routes a visitor who is not logged in may still call when the API is closed to them. It is the one place that decides, so
 * that what the audit says is what HTTP does.
 *
 * A prefix is made of whole segments (`wc/store` opens `/wc/store/v1/cart`, not `/wc/storefront`), and the case is ignored because
 * WordPress ignores it when it matches a route.
 *
 * @internal
 */
final readonly class RestAllowList
{
    /**
     * What visitors need on an ordinary site: the embeds of this site by others, the forms of Contact Form 7, and the shop of the
     * WooCommerce cart and checkout blocks. A prefix of a plugin that is not installed opens nothing.
     */
    public const array DEFAULTS = ['oembed/1.0/embed', 'contact-form-7/v1/contact-forms', 'wc/store'];

    /** The public routes of pollora/headless: its front end has no WordPress login, even to trade a preview token. */
    public const string HEADLESS = 'headless/v1';

    /** @var list<string> */
    private array $prefixes;

    /**
     * @param  list<string>  $prefixes  without leading or trailing slash
     */
    public function __construct(array $prefixes)
    {
        $this->prefixes = array_values(array_unique(array_map(strtolower(...), $prefixes)));
    }

    /**
     * The defaults, those of pollora/headless when it is there, and what the owner added.
     *
     * @param  list<string>  $extra
     */
    public static function with(array $extra, bool $headless): self
    {
        return new self([...self::DEFAULTS, ...($headless ? [self::HEADLESS] : []), ...$extra]);
    }

    /**
     * The index (`/`) is open: it is where a client learns how to authorise an application password. Its list of routes is taken out
     * by whoever serves it.
     *
     * @param  string  $route  as WordPress matches it: `/wp/v2/posts`
     */
    public function allows(string $route): bool
    {
        if ($route === '/') {
            return true;
        }

        $route = strtolower($route);

        foreach ($this->prefixes as $prefix) {
            if ($route === '/'.$prefix || str_starts_with($route, '/'.$prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether anything below a namespace is open: the index then still names it.
     */
    public function opens(string $namespace): bool
    {
        $namespace = strtolower($namespace);

        foreach ($this->prefixes as $prefix) {
            if ($prefix === $namespace || str_starts_with($prefix, $namespace.'/') || str_starts_with($namespace, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}

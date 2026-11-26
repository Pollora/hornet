<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Admin;

use Closure;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Infrastructure\WordPress\HookRegistrar;
use Pollora\Hornet\Infrastructure\WordPress\I18n\WpTranslator;
use Pollora\Hornet\Infrastructure\WordPress\Module;

/**
 * The Tools > Security screen: one entry in the Tools menu (never a menu of its own), and a page that holds only a container for the script.
 *
 * On a network the entry is in the network administration, under Settings, for the super administrators.
 *
 * The script and the style are compiled and kept in the package (`assets/admin`): nothing runs npm on a site. They are not served from
 * the package's folder, which on Bedrock and on Pollora is outside the web root: this module sends them itself, to a person who may
 * see the screen and to nobody else.
 */
final class AdminPageModule implements Module
{
    public const string PAGE = 'hornet-security';

    /** The only files it sends, with their type. */
    private const array ASSETS = [
        'index.js' => 'application/javascript; charset=UTF-8',
        'style-index.css' => 'text/css; charset=UTF-8',
        'style-index-rtl.css' => 'text/css; charset=UTF-8',
    ];

    private ?string $screen = null;

    /** @var list<string> the scripts the screen needs that this WordPress does not have */
    private array $missing = [];

    /**
     * @param  string  $assets  the folder of the compiled files
     * @param  Closure(): never  $exit  ends the request after a file is sent
     */
    public function __construct(
        private readonly bool $network,
        private readonly string $assets,
        private readonly Closure $exit,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addAction($this->network ? 'network_admin_menu' : 'admin_menu', $this->menu(...));
        $hooks->addAction('admin_enqueue_scripts', $this->enqueue(...));
        $hooks->addAction('admin_init', $this->send(...));
    }

    public function menu(): void
    {
        $screen = $this->network
            ? add_submenu_page('settings.php', $this->title(), $this->title(), 'manage_network_options', self::PAGE, $this->render(...))
            : add_management_page($this->title(), $this->title(), 'manage_options', self::PAGE, $this->render(...));

        $this->screen = is_string($screen) ? $screen : null;
    }

    /**
     * The hook name that WordPress gave the page (`tools_page_hornet-security` in the administration), null until the menu is built.
     */
    public function screen(): ?string
    {
        return $this->screen;
    }

    public function render(): void
    {
        if (! AdminAccess::allowed()) {
            wp_die(esc_html(Message::of('You are not allowed to see this page.')->render(WpTranslator::forPackage())), '', ['response' => 403]);
        }

        echo '<div class="wrap"><h1 class="screen-reader-text">'.esc_html($this->title()).'</h1><hr class="wp-header-end">';

        // A script that WordPress does not know is not printed, and the page would stay empty without a word: say it
        if ($this->missing !== []) {
            echo '<div class="notice notice-error inline"><p>'.esc_html(Message::of('This screen needs scripts that this version of WordPress does not have (%s). Use `wp hornet status` and `wp hornet scan`: they give the same information.', implode(', ', $this->missing))->render(WpTranslator::forPackage())).'</p></div>';
        }

        echo '<div id="hornet-admin"></div>';
        echo '<noscript><p>'.esc_html(Message::of('This screen needs JavaScript. The same information is given by `wp hornet status` and `wp hornet scan`.')->render(WpTranslator::forPackage())).'</p></noscript></div>';
    }

    private function title(): string
    {
        return Message::of('Security')->render(WpTranslator::forPackage());
    }

    public function enqueue(string $hook): void
    {
        if ($this->screen === null || $hook !== $this->screen) {
            return;
        }

        $asset = is_file($this->assets.'/index.asset.php') ? include $this->assets.'/index.asset.php' : null;

        if (! is_array($asset) || ! is_file($this->assets.'/index.js')) {
            return;
        }

        $version = is_string($asset['version'] ?? null) ? $asset['version'] : 'dev';
        $dependencies = is_array($asset['dependencies'] ?? null) ? array_values(array_filter($asset['dependencies'], is_string(...))) : [];
        $this->missing = array_values(array_filter($dependencies, static fn (string $handle): bool => ! wp_script_is($handle, 'registered')));

        if ($this->missing !== []) {
            return;
        }

        wp_enqueue_script('hornet-admin', $this->url('index.js', $version), $dependencies, $version, true);
        // The screen speaks the language of the person: the JSON of languages/ is read by WordPress for the locale of the user, with the handle
        wp_set_script_translations('hornet-admin', 'pollora-hornet', dirname($this->assets, 2).'/languages');
        wp_enqueue_style('wp-components');

        $style = is_rtl() ? 'style-index-rtl.css' : 'style-index.css';

        if (is_file($this->assets.'/'.$style)) {
            wp_enqueue_style('hornet-admin', $this->url($style, $version), ['wp-components'], $version);
        }
    }

    /**
     * Sends one of the compiled files, when the request asks for it.
     */
    public function send(): void
    {
        $name = $_GET['hornet-asset'] ?? null;

        if (! is_string($name)) {
            return;
        }

        if (! AdminAccess::allowed()) {
            wp_die(esc_html('You are not allowed to see this file.'), '', ['response' => 403]);
        }

        // The file is picked from the list of the files it sends, by comparing: the name that came with the request is never put in a path
        foreach (self::ASSETS as $file => $type) {
            $path = $this->assets.'/'.$file;

            if ($file === $name && is_file($path)) {
                header('Content-Type: '.$type);
                header('X-Content-Type-Options: nosniff');
                // The address holds the version of the file: it never changes under it
                header('Cache-Control: private, max-age=31536000, immutable');
                header('Content-Length: '.filesize($path));
                readfile($path);

                ($this->exit)();
            }
        }

        status_header(404);
        ($this->exit)();
    }

    private function url(string $file, string $version): string
    {
        $base = $this->network ? network_admin_url('admin.php') : admin_url('admin.php');

        return add_query_arg(['hornet-asset' => $file, 'ver' => $version], $base);
    }
}

<?php

declare(strict_types=1);

/*
 * What the unit suite needs of WordPress besides its functions (which Brain Monkey stubs, test by test): a few constants, a
 * throwaway folder that stands for the install, and stand-ins for the classes that the adapters name.
 */

namespace {
    use Pollora\Hornet\Tests\Fixtures\FakeApplicationPasswords;
    use Pollora\Hornet\Tests\Fixtures\FakeRestRequest;
    use Pollora\Hornet\Tests\Fixtures\FakeSessionTokens;
    use Pollora\Hornet\Tests\Fixtures\FakeTheme;
    use Pollora\Hornet\Tests\Fixtures\FakeWpdb;

    foreach ([
        'wpdb' => FakeWpdb::class,
        'WP_REST_Request' => FakeRestRequest::class,
        'WP_Theme' => FakeTheme::class,
        'WP_Session_Tokens' => FakeSessionTokens::class,
        'WP_Application_Passwords' => FakeApplicationPasswords::class,
    ] as $name => $standIn) {
        if (! class_exists($name, false)) {
            class_alias($standIn, $name);
        }
    }

    // One folder per process: parallel runs do not meet
    $install = sys_get_temp_dir().'/hornet-unit-'.getmypid().'-'.bin2hex(random_bytes(4));

    foreach (['wp-admin/includes', 'wp-content/plugins', 'wp-content/themes', 'wp-content/uploads'] as $folder) {
        mkdir($install.'/'.$folder, 0o777, true);
    }

    file_put_contents($install.'/wp-admin/includes/upgrade.php', "<?php\n");
    file_put_contents($install.'/wp-admin/includes/plugin.php', "<?php\n");

    defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');
    defined('ABSPATH') || define('ABSPATH', $install.'/');
    defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', $install.'/wp-content');
    defined('WP_PLUGIN_DIR') || define('WP_PLUGIN_DIR', $install.'/wp-content/plugins');

    register_shutdown_function(static function () use ($install): void {
        if (! is_dir($install)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($install, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($install);
    });
}

// The command line sends no header: the two namespaces that send some are given functions that keep them instead

namespace Pollora\Hornet\Infrastructure\WordPress {
    use Pollora\Hornet\Tests\Fixtures\SentHeaders;

    function header(string $header, bool $replace = true): void
    {
        SentHeaders::$sent[] = [$header, $replace];
    }

    function headers_sent(): bool
    {
        return SentHeaders::$alreadySent;
    }
}

namespace Pollora\Hornet\Infrastructure\WordPress\Admin {
    use Pollora\Hornet\Tests\Fixtures\SentHeaders;

    function header(string $header, bool $replace = true): void
    {
        SentHeaders::$sent[] = [$header, $replace];
    }
}

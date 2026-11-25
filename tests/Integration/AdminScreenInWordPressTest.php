<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Application\Scan\ScanJob;
use Pollora\Hornet\Application\Scan\Scanner;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\Journal\JournalQuery;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Domain\Scan\ScanState;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\Config\Settings;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminAccess;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminPageModule;
use Pollora\Hornet\Infrastructure\WordPress\Admin\AdminRestModule;
use Pollora\Hornet\Infrastructure\WordPress\AuditorFactory;
use Pollora\Hornet\Infrastructure\WordPress\Bootstrap;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalSchema;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TableJournal;
use Pollora\Hornet\Infrastructure\WordPress\Journal\WpRequestContext;
use Pollora\Hornet\Infrastructure\WordPress\OptionRunLock;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStateStore;
use Pollora\Hornet\Infrastructure\WordPress\OptionScanStore;
use Pollora\Hornet\Infrastructure\WordPress\OptionSetAsideStore;
use Pollora\Hornet\Infrastructure\WordPress\ScanModule;
use Pollora\Hornet\Infrastructure\WordPress\ScannerFactory;
use Pollora\Hornet\Infrastructure\WordPress\WordPressEnvironment;
use Pollora\Hornet\Infrastructure\WordPress\WordPressHooks;

/**
 * Boots Hornet with these settings and makes WordPress build its REST server again, so that it holds the routes of the screen.
 *
 * @param  array<string, mixed>  $config
 */
function bootAdmin(array $config = []): void
{
    // A second boot must not stack on the first: the routes of the first one would answer
    if (isset($GLOBALS['hornet_test_hooks'])) {
        // Copies: a boot adds to the hooks it finds, and the saved ones must stay as they were
        $GLOBALS['wp_filter'] = array_map(static fn (WP_Hook $hook): WP_Hook => clone $hook, $GLOBALS['hornet_test_hooks']);
    }

    $settings = Settings::fromArray([...$config, 'site_health' => false]);
    Bootstrap::boot($settings, new WordPressHooks, new WordPressEnvironment($settings));
    $GLOBALS['wp_rest_server'] = null;
}

function restAs(?int $user, string $method, string $route, array $params = []): WP_REST_Response
{
    wp_set_current_user($user ?? 0);
    $request = new WP_REST_Request($method, $route);

    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    return rest_do_request($request);
}

beforeEach(function (): void {
    // Hornet is booted again by these tests, with the journal on in some: every hook is put back as it was, whatever Hornet attached to it
    $this->hooks = array_map(static fn (WP_Hook $hook): WP_Hook => clone $hook, $GLOBALS['wp_filter']);
    $GLOBALS['hornet_test_hooks'] = array_map(static fn (WP_Hook $hook): WP_Hook => clone $hook, $GLOBALS['wp_filter']);

    require_once ABSPATH.'wp-admin/includes/user.php';
    $this->subscriber = wp_insert_user(['user_login' => 'hornet-subscriber-'.wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'subscriber']);
});

afterEach(function (): void {
    wp_set_current_user(0);
    wp_delete_user($this->subscriber);
    $GLOBALS['wp_filter'] = $this->hooks;

    wp_clear_scheduled_hook(ScanModule::NOW_HOOK);
    delete_option(OptionScanStateStore::OPTION);
    delete_option(OptionScanStore::OPTION);
    delete_option('hornet_scan_lock');
    $GLOBALS['wp_rest_server'] = null;
});

describe('who may ask', function (): void {
    it('lets an administrator in, and nobody else', function (): void {
        bootAdmin();

        expect(restAs(1, 'GET', '/hornet/v1/status')->get_status())->toBe(200)
            ->and(restAs($this->subscriber, 'GET', '/hornet/v1/status')->get_status())->toBe(403)
            ->and(restAs(null, 'GET', '/hornet/v1/status')->get_status())->toBeIn([401, 403])
            ->and(restAs($this->subscriber, 'POST', '/hornet/v1/scan')->get_status())->toBe(403)
            ->and(restAs(null, 'POST', '/hornet/v1/scan')->get_status())->toBeIn([401, 403])
            ->and(restAs($this->subscriber, 'POST', '/hornet/v1/notify/test', ['channel' => 'x'])->get_status())->toBe(403)
            ->and(wp_get_scheduled_event(ScanModule::NOW_HOOK))->toBeFalse();
    });

    it('is the right of an administrator on a site', function (): void {
        wp_set_current_user(1);

        expect(AdminAccess::allowed())->toBeTrue();

        wp_set_current_user($this->subscriber);

        expect(AdminAccess::allowed())->toBeFalse();
    });

    it('has no route at all when the owner turns the screen off', function (): void {
        bootAdmin(['admin' => false]);

        expect(restAs(1, 'GET', '/hornet/v1/status')->get_status())->toBe(404);
    });
});

describe('discretion', function (): void {
    it('does not announce its routes to whoever asks for the index of the REST API', function (): void {
        bootAdmin();

        $anonymous = restAs(null, 'GET', '/')->get_data();
        $subscriber = restAs($this->subscriber, 'GET', '/')->get_data();
        $admin = restAs(1, 'GET', '/')->get_data();

        expect($anonymous['namespaces'])->not->toContain('hornet/v1')->and(array_keys($anonymous['routes']))->not->toContain('/hornet/v1/status')
            ->and($subscriber['namespaces'])->not->toContain('hornet/v1')
            ->and($anonymous['namespaces'])->toContain('wp/v2')
            // The people who may use the routes are told
            ->and($admin['namespaces'])->toContain('hornet/v1');
    });
});

describe('a WordPress that lacks what the screen needs', function (): void {
    it('says so, and prints no script that would leave the page empty', function (): void {
        wp_set_current_user(1);
        $assets = sys_get_temp_dir().'/hornet-assets-'.bin2hex(random_bytes(4));
        mkdir($assets);
        file_put_contents($assets.'/index.asset.php', "<?php return array('dependencies' => array('wp-element', 'wp-a-script-that-does-not-exist'), 'version' => 'x');");
        file_put_contents($assets.'/index.js', '// nothing');
        $module = new AdminPageModule(false, $assets, static fn (): never => throw new RuntimeException('exit'));
        $module->menu();
        global $submenu;

        $module->enqueue((string) $module->screen());
        ob_start();
        $module->render();
        $html = (string) ob_get_clean();
        $enqueued = wp_script_is('hornet-admin', 'enqueued');

        unlink($assets.'/index.asset.php');
        unlink($assets.'/index.js');
        rmdir($assets);

        expect($enqueued)->toBeFalse()->and($html)->toContain('notice-error')->toContain('wp-a-script-that-does-not-exist')->toContain('wp hornet scan')
            ->and($submenu['tools.php'] ?? [])->not->toBe([]);
    });

    it('enqueues the script when everything it needs is there', function (): void {
        wp_set_current_user(1);
        $module = new AdminPageModule(false, dirname(__DIR__, 2).'/assets/admin', static fn (): never => throw new RuntimeException('exit'));
        $module->menu();

        $module->enqueue((string) $module->screen());

        expect(wp_script_is('hornet-admin', 'enqueued'))->toBeTrue();

        wp_dequeue_script('hornet-admin');
    });

    it('does not enqueue anything on another page', function (): void {
        wp_set_current_user(1);
        wp_dequeue_script('hornet-admin');
        $module = new AdminPageModule(false, dirname(__DIR__, 2).'/assets/admin', static fn (): never => throw new RuntimeException('exit'));
        $module->menu();

        $module->enqueue('index.php');

        expect(wp_script_is('hornet-admin', 'enqueued'))->toBeFalse();
    });
});

describe('the status', function (): void {
    it('gives the audit, the last scan, the settings and the channels', function (): void {
        bootAdmin();

        $data = restAs(1, 'GET', '/hornet/v1/status')->get_data();

        expect($data)->toHaveKeys(['counts', 'worst', 'findings', 'scan', 'settings', 'channels', 'network'])
            ->and($data['counts']['total'])->toBe(count($data['findings']))
            ->and($data['scan']['tasks'])->not->toBe([])
            ->and($data['scan']['running'])->toBeFalse()
            ->and($data['network'])->toBeFalse();
    });

    it('names a channel and never says where it goes', function (): void {
        putenv('HORNET_IT_HOOK=https://hooks.slack.test/services/T000/B000/XXXXSECRETXXXX');
        bootAdmin(['notifications' => ['channels' => ['slack' => ['type' => 'slack', 'url' => ['env' => 'HORNET_IT_HOOK'], 'min_severity' => 'high']]]]);

        $response = restAs(1, 'GET', '/hornet/v1/status');
        $body = (string) wp_json_encode($response->get_data());
        putenv('HORNET_IT_HOOK');

        expect($response->get_data()['channels'])->toBe([['name' => 'slack', 'type' => 'slack', 'min_severity' => 'high']])
            ->and($body)->not->toContain('hooks.slack')->not->toContain('SECRET');
    });

    it('does not carry a salt or a key of the site', function (): void {
        bootAdmin();

        $body = (string) wp_json_encode(restAs(1, 'GET', '/hornet/v1/status')->get_data());

        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'DB_PASSWORD'] as $constant) {
            if (defined($constant) && strlen((string) constant($constant)) > 12) {
                expect($body)->not->toContain((string) constant($constant));
            }
        }
    });
});

describe('the scan', function (): void {
    it('asks for a scan, does not wait for it, and plans it in the cron', function (): void {
        bootAdmin();

        $response = restAs(1, 'POST', '/hornet/v1/scan');

        expect($response->get_status())->toBe(202)->and($response->get_data()['queued'])->toBeTrue()
            ->and(wp_next_scheduled(ScanModule::NOW_HOOK))->not->toBeFalse()
            ->and((new OptionScanStateStore)->get()->status)->toBe(ScanState::QUEUED)
            ->and(restAs(1, 'GET', '/hornet/v1/scan')->get_data()['status'])->toBe('queued')
            ->and(restAs(1, 'GET', '/hornet/v1/status')->get_data()['scan'])->toMatchArray(['running' => true, 'status' => 'queued']);
    });

    it('refuses a second scan while one is waiting or running', function (): void {
        bootAdmin();
        restAs(1, 'POST', '/hornet/v1/scan');

        $second = restAs(1, 'POST', '/hornet/v1/scan');

        expect($second->get_status())->toBe(409)->and($second->get_data()['queued'])->toBeFalse();
    });

    it('does not wait for a scan that has died', function (): void {
        bootAdmin();
        (new OptionScanStateStore)->save(new ScanState(ScanState::RUNNING, 'plugin-integrity', time() - 7200));

        expect(restAs(1, 'POST', '/hornet/v1/scan')->get_status())->toBe(202);
    });

    it('runs when the cron fires its event, and ends idle, with a result, and with the lock let go', function (): void {
        $settings = Settings::fromArray(['site_health' => false, 'disabled_checks' => ['core-integrity', 'plugin-integrity'], 'scanner' => ['probes' => false]]);
        $states = [];
        $store = new OptionScanStateStore;
        // The state is read as each task starts: the screen sees which one it is at
        add_filter('pre_update_option_'.OptionScanStateStore::OPTION, static function (mixed $value) use (&$states): mixed {
            $decoded = json_decode((string) $value, true);
            $states[] = [$decoded['status'] ?? '', $decoded['task'] ?? null];

            return $value;
        });
        $job = new ScanJob(static fn (Closure $progress): ?Scanner => ScannerFactory::forWordPress($settings, true, $progress), new OptionRunLock('hornet_scan_lock'), $store, new SystemClock, static fn (): array => []);
        (new ScanModule($job, 'off'))->register(new WordPressHooks);
        $job->queued();

        do_action(ScanModule::NOW_HOOK);

        $scan = (new OptionScanStore)->last();
        remove_all_filters('pre_update_option_'.OptionScanStateStore::OPTION);

        expect($store->get()->status)->toBe('idle')
            ->and(array_column($states, 0))->toContain('queued')->toContain('running')->and(end($states)[0])->toBe('idle')
            ->and(array_filter(array_column($states, 1)))->toContain('theme-integrity')->toContain('suspicious-files')
            ->and($scan?->finding('suspicious-files'))->not->toBeNull()
            ->and(get_option('hornet_scan_lock'))->toBeFalse();
    });
});

describe('the test message', function (): void {
    it('says there is no such channel', function (): void {
        bootAdmin();

        expect(restAs(1, 'POST', '/hornet/v1/notify/test', ['channel' => 'nowhere'])->get_status())->toBe(404);
    });

    it('refuses a channel name that is not a plain one', function (string $name): void {
        bootAdmin();

        expect(restAs(1, 'POST', '/hornet/v1/notify/test', ['channel' => $name])->get_status())->toBe(400);
    })->with(['../x', 'a b', '', str_repeat('a', 65), 'x;y']);
});

describe('the page', function (): void {
    it('puts one entry in the Tools menu, and none of its own', function (): void {
        wp_set_current_user(1);
        global $submenu, $menu;
        $before = count($menu ?? []);
        $module = new AdminPageModule(false, dirname(__DIR__, 2).'/assets/admin', static fn (): never => throw new RuntimeException('exit'));

        $module->menu();

        $slugs = array_column($submenu['tools.php'] ?? [], 2);

        expect($slugs)->toContain('hornet-security')->and(count($menu ?? []))->toBe($before);
    });

    it('draws a container for the script, and nothing else', function (): void {
        wp_set_current_user(1);
        $module = new AdminPageModule(false, dirname(__DIR__, 2).'/assets/admin', static fn (): never => throw new RuntimeException('exit'));

        ob_start();
        $module->render();
        $html = (string) ob_get_clean();

        expect($html)->toContain('<div id="hornet-admin"></div>')->and($html)->toContain('<noscript>');
    });

    it('sends the compiled script to an administrator, with the right type, and ends the request', function (): void {
        wp_set_current_user(1);
        $assets = dirname(__DIR__, 2).'/assets/admin';
        $module = new AdminPageModule(false, $assets, static fn (): never => throw new RuntimeException('exit'));
        $_GET['hornet-asset'] = 'index.js';

        ob_start();

        try {
            $module->send();
        } catch (RuntimeException) {
            // The request ends here
        } finally {
            $sent = (string) ob_get_clean();
            unset($_GET['hornet-asset']);
        }

        expect($sent)->toBe((string) file_get_contents($assets.'/index.js'));
    });

    it('sends nothing to someone who may not see the screen', function (): void {
        wp_set_current_user($this->subscriber);
        $module = new AdminPageModule(false, dirname(__DIR__, 2).'/assets/admin', static fn (): never => throw new RuntimeException('exit'));
        $_GET['hornet-asset'] = 'index.js';
        add_filter('wp_die_handler', static fn (): Closure => static function (string $message): never {
            throw new RuntimeException('denied');
        });

        try {
            ob_start();
            $module->send();
            $result = 'sent';
        } catch (RuntimeException $error) {
            $result = $error->getMessage();
        } finally {
            $output = (string) ob_get_clean();
            unset($_GET['hornet-asset']);
            remove_all_filters('wp_die_handler');
        }

        expect($result)->toBe('denied')->and($output)->not->toContain('function');
    });

    it('sends only the files it knows, not a path', function (string $name): void {
        wp_set_current_user(1);
        $module = new AdminPageModule(false, dirname(__DIR__, 2).'/assets/admin', static fn (): never => throw new RuntimeException('exit'));
        $_GET['hornet-asset'] = $name;

        ob_start();

        try {
            $module->send();
            $result = 'sent';
        } catch (RuntimeException) {
            $result = 'ended';
        } finally {
            $output = (string) ob_get_clean();
            unset($_GET['hornet-asset']);
        }

        expect($result)->toBe('ended')->and($output)->toBe('');
    })->with(['../../composer.json', 'index.asset.php', '../index.js', 'nothing.js', '']);

    it('does nothing when the request does not ask for a file', function (): void {
        wp_set_current_user(1);
        $module = new AdminPageModule(false, dirname(__DIR__, 2).'/assets/admin', static fn (): never => throw new RuntimeException('exit'));

        ob_start();
        $module->send();

        expect((string) ob_get_clean())->toBe('');
    });
});

it('has the routes under one namespace', function (): void {
    expect(AdminRestModule::NAMESPACE)->toBe('hornet/v1');
});

describe('setting a finding aside from the screen', function (): void {
    beforeEach(function (): void {
        bootAdmin();
        delete_option(OptionSetAsideStore::OPTION);
    });

    afterEach(function (): void {
        delete_option(OptionSetAsideStore::OPTION);
    });

    /** The check that is open on this site: the address is http. */
    function findingOf(string $check): array
    {
        $found = array_values(array_filter(restAs(1, 'GET', '/hornet/v1/status')->get_data()['findings'], static fn (array $finding): bool => $finding['check'] === $check));

        return $found[0] ?? [];
    }

    it('sets it aside on a reason, and the audit sees it set aside with that reason', function (): void {
        $before = findingOf('https');

        $response = restAs(1, 'POST', '/hornet/v1/findings/https/ignore', ['reason' => 'This is a test site, on purpose.']);
        $after = findingOf('https');

        expect($before)->toMatchArray(['status' => 'open', 'can_set_aside' => true])
            ->and($response->get_status())->toBe(200)
            ->and($after)->toMatchArray(['status' => 'ignored', 'ignored' => 'This is a test site, on purpose.', 'ignored_by' => 'screen', 'can_restore' => true, 'can_set_aside' => false])
            ->and($after['ignored_login'])->not->toBeNull()
            ->and(restAs(1, 'GET', '/hornet/v1/status')->get_data()['counts']['ignored'])->toBe(1);
    });

    it('brings it back', function (): void {
        restAs(1, 'POST', '/hornet/v1/findings/https/ignore', ['reason' => 'This is a test site, on purpose.']);

        $response = restAs(1, 'DELETE', '/hornet/v1/findings/https/ignore');

        expect($response->get_status())->toBe(200)->and(findingOf('https'))->toMatchArray(['status' => 'open', 'ignored_by' => null])
            ->and(get_option(OptionSetAsideStore::OPTION))->toBe('[]');
    });

    it('asks for a reason, and says what it refuses', function (): void {
        $post = static fn (string $check, string $reason): WP_REST_Response => restAs(1, 'POST', '/hornet/v1/findings/'.$check.'/ignore', ['reason' => $reason]);

        expect($post('https', 'no')->get_status())->toBe(422)->and($post('https', 'no')->get_data()['kind'])->toBe('reason')
            ->and($post('nothing-like-it', 'A reason that is long enough.')->get_status())->toBe(404)
            // What passes has nothing to hide
            ->and($post('salts', 'A reason that is long enough.')->get_status())->toBeIn([200, 409])
            ->and(get_option(OptionSetAsideStore::OPTION))->toBeFalse();
    });

    it('is for the people who may see the screen, and nobody else', function (): void {
        expect(restAs($this->subscriber, 'POST', '/hornet/v1/findings/https/ignore', ['reason' => 'A reason that is long enough.'])->get_status())->toBe(403)
            ->and(restAs(null, 'POST', '/hornet/v1/findings/https/ignore', ['reason' => 'A reason that is long enough.'])->get_status())->toBeIn([401, 403])
            ->and(restAs($this->subscriber, 'DELETE', '/hornet/v1/findings/https/ignore')->get_status())->toBe(403)
            ->and(get_option(OptionSetAsideStore::OPTION))->toBeFalse();
    });

    it('does not let a check id into the route that is not a plain one', function (string $check): void {
        expect(restAs(1, 'POST', '/hornet/v1/findings/'.$check.'/ignore', ['reason' => 'A reason that is long enough.'])->get_status())->toBe(404);
    })->with(['../options', 'a b', 'UPPER', '-start', 'a/b']);

    it('says it in the journal when there is one, with the reason and who did it', function (): void {
        JournalSchema::drop();
        JournalSchema::ensure();
        bootAdmin(['journal' => ['enabled' => true]]);

        restAs(1, 'POST', '/hornet/v1/findings/https/ignore', ['reason' => 'This is a test site, on purpose.']);
        restAs(1, 'DELETE', '/hornet/v1/findings/https/ignore');

        $events = (new TableJournal)->find(new JournalQuery('hornet.', limit: 10));
        $types = array_map(static fn ($event): string => $event->type, $events);

        JournalSchema::drop();

        expect($types)->toBe(['hornet.finding_restored', 'hornet.finding_set_aside'])
            ->and($events[1])->toMatchObject(['subject' => 'check:https', 'userId' => 1, 'details' => ['reason' => 'This is a test site, on purpose.']]);
    });

    it('still works, and remembers who and when, when the journal is off', function (): void {
        restAs(1, 'POST', '/hornet/v1/findings/https/ignore', ['reason' => 'This is a test site, on purpose.']);

        $stored = (new OptionSetAsideStore)->all()['https'];

        expect($stored->userId)->toBe(1)->and($stored->login)->not->toBe('')->and($stored->at)->toBeGreaterThan(time() - 60);
    });

    it('lets the settings file win: what it sets aside keeps its reason and cannot be changed from the screen', function (): void {
        bootAdmin(['ignore' => [['check' => 'https', 'reason' => 'Set in the file.']]]);

        expect(findingOf('https'))->toMatchArray(['status' => 'ignored', 'ignored' => 'Set in the file.', 'ignored_by' => 'file', 'can_set_aside' => false, 'can_restore' => false])
            ->and(restAs(1, 'POST', '/hornet/v1/findings/https/ignore', ['reason' => 'A reason that is long enough.'])->get_status())->toBe(409)
            ->and(restAs(1, 'DELETE', '/hornet/v1/findings/https/ignore')->get_status())->toBe(409);

        // Set aside from the screen earlier, then in the file: the file's reason is the one that stands
        (new OptionSetAsideStore)->put(new SetAside('https', 'From the screen.', 1, 'admin', time()));

        expect(findingOf('https')['ignored'])->toBe('Set in the file.');
    });

    it('does not make the audit refuse an id that no longer exists: a check set aside and then turned off', function (): void {
        (new OptionSetAsideStore)->put(new SetAside('vulnerabilities', 'From the screen, earlier.', 1, 'admin', time()));
        (new OptionSetAsideStore)->put(new SetAside('removed-check', 'From an older version.', 1, 'admin', time()));

        $auditor = AuditorFactory::forWordPress(Settings::fromArray([]));

        expect(fn () => $auditor->run(new WordPressEnvironment))->not->toThrow(Throwable::class)->and(array_map(static fn ($check): string => $check->id(), $auditor->checks()))->not->toContain('vulnerabilities');
    });

    it('is what the other surfaces see too: Site Health and the command line audit set it aside', function (): void {
        restAs(1, 'POST', '/hornet/v1/findings/https/ignore', ['reason' => 'This is a test site, on purpose.']);

        $report = AuditorFactory::forWordPress(Settings::fromArray([]))->run(new WordPressEnvironment);

        expect($report->find('https')?->ignoredReason)->toBe('This is a test site, on purpose.')->and($report->find('https')?->isOpen())->toBeFalse();
    });
});

describe('the journal on the screen', function (): void {
    beforeEach(function (): void {
        JournalSchema::drop();
        JournalSchema::ensure();
    });

    afterEach(function (): void {
        JournalSchema::drop();
    });

    it('says it is off, and gives nothing, when the owner has not turned it on', function (): void {
        bootAdmin();

        $data = restAs(1, 'GET', '/hornet/v1/journal')->get_data();

        expect($data)->toMatchArray(['enabled' => false, 'events' => [], 'total' => 0]);
    });

    it('gives the events, the newest first, a page at a time, and narrows them', function (): void {
        bootAdmin(['journal' => ['enabled' => true]]);
        $recorder = new JournalRecorder(new TableJournal, new WpRequestContext(static fn (): ClientIp => new ClientIp('203.0.113.50', '203.0.113.50')), new SystemClock);

        foreach (range(1, 30) as $n) {
            $recorder->record('plugin.activated', 'plugin:p'.$n, [], 1, 'admin');
        }

        $recorder->record('login.failed', 'user:1', ['reason' => 'incorrect_password'], 0, '');

        $first = restAs(1, 'GET', '/hornet/v1/journal', ['per_page' => 10])->get_data();
        $third = restAs(1, 'GET', '/hornet/v1/journal', ['per_page' => 10, 'page' => 4])->get_data();
        $logins = restAs(1, 'GET', '/hornet/v1/journal', ['type' => 'login.'])->get_data();

        expect($first)->toMatchArray(['enabled' => true, 'total' => 31, 'page' => 1, 'pages' => 4])->and($first['events'])->toHaveCount(10)
            ->and($first['events'][0])->toMatchArray(['type' => 'login.failed', 'subject' => 'user:1', 'ip' => '203.0.113.0'])
            ->and($third['events'])->toHaveCount(1)
            ->and($logins['total'])->toBe(1)->and($first['types'])->toContain('login')->toContain('plugin');
    });

    it('refuses a filter that it does not know, and is for the people who may see the screen', function (): void {
        bootAdmin(['journal' => ['enabled' => true]]);

        expect(restAs(1, 'GET', '/hornet/v1/journal', ['type' => 'login.exploded'])->get_status())->toBe(400)
            ->and(restAs(1, 'GET', '/hornet/v1/journal', ['type' => "x' OR 1=1 -- "])->get_status())->toBe(400)
            ->and(restAs(1, 'GET', '/hornet/v1/journal', ['source' => 'mars'])->get_status())->toBe(400)
            ->and(restAs(1, 'GET', '/hornet/v1/journal', ['per_page' => 5000])->get_status())->toBe(400)
            ->and(restAs($this->subscriber, 'GET', '/hornet/v1/journal')->get_status())->toBe(403)
            ->and(restAs(null, 'GET', '/hornet/v1/journal')->get_status())->toBeIn([401, 403]);
    });
});

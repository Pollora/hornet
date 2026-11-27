<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Exceptions\ContentReadException;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Domain\Scan\ContentEntry;
use Pollora\Hornet\Domain\Scan\InstalledPlugin;
use Pollora\Hornet\Infrastructure\Config\ChannelSpec;
use Pollora\Hornet\Infrastructure\Config\ChannelType;
use Pollora\Hornet\Infrastructure\Config\EnvironmentSecrets;
use Pollora\Hornet\Infrastructure\Config\HeaderSettings;
use Pollora\Hornet\Infrastructure\Config\SettingsLoader;
use Pollora\Hornet\Infrastructure\Notification\ChannelFactory;
use Pollora\Hornet\Infrastructure\Notification\Channels\DiscordChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\MailChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\NtfyChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\SlackChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\TeamsChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\TelegramChannel;
use Pollora\Hornet\Infrastructure\Notification\Channels\WebhookChannel;
use Pollora\Hornet\Infrastructure\WordPress\HeadersModule;
use Pollora\Hornet\Infrastructure\WordPress\I18n\WpTranslator;
use Pollora\Hornet\Infrastructure\WordPress\WordPressHooks;
use Pollora\Hornet\Infrastructure\WordPress\WpContentStore;
use Pollora\Hornet\Infrastructure\WordPress\WpHttpClient;
use Pollora\Hornet\Infrastructure\WordPress\WpMailer;
use Pollora\Hornet\Infrastructure\WordPress\WpPluginInventory;
use Pollora\Hornet\Infrastructure\WordPress\WpThemeInventory;
use Pollora\Hornet\Tests\Fixtures\FakeClock;
use Pollora\Hornet\Tests\Fixtures\FakeHttpClient;
use Pollora\Hornet\Tests\Fixtures\FakeMailer;
use Pollora\Hornet\Tests\Fixtures\FakeTheme;
use Pollora\Hornet\Tests\Fixtures\FakeWpdb;
use Pollora\Hornet\Tests\Fixtures\FakeWpError;
use Pollora\Hornet\Tests\Fixtures\RecordingHooks;
use Pollora\Hornet\Tests\Fixtures\Samples;
use Pollora\Hornet\Tests\Fixtures\SentHeaders;

describe('the HTTP client', function (): void {
    beforeEach(function (): void {
        $this->calls = [];
        $calls = &$this->calls;

        foreach (['wp_remote_post', 'wp_safe_remote_post', 'wp_remote_get', 'wp_safe_remote_get'] as $function) {
            Functions\when($function)->alias(function (string $url, array $args) use ($function, &$calls): array {
                $calls[] = [$function, $url, $args];

                return ['response' => ['code' => 201]];
            });
        }

        Functions\when('is_wp_error')->alias(static fn (mixed $value): bool => $value instanceof FakeWpError);
        Functions\when('wp_remote_retrieve_response_code')->justReturn('201');
        Functions\when('wp_remote_retrieve_body')->justReturn('abcdefghij');
        Functions\when('wp_remote_retrieve_header')->justReturn('30');
        Functions\when('wp_remote_retrieve_headers')->justReturn(['X-Frame-Options' => 'DENY', 'Set-Cookie' => ['a=1', 'b=2']]);
    });

    it('posts through the safe function, follows no redirect and cuts the answer', function (): void {
        $response = (new WpHttpClient)->post(Secret::of('https://hooks.example/x'), ['A' => 'b'], 'payload', false, 4, 3);

        expect($this->calls)->toBe([['wp_safe_remote_post', 'https://hooks.example/x', ['timeout' => 3, 'redirection' => 0, 'headers' => ['A' => 'b'], 'body' => 'payload', 'user-agent' => 'Hornet (WordPress security)']]])
            ->and([$response->status, $response->retryAfter, $response->body])->toBe([201, 30, 'abcd']);
    });

    it('gets through the safe function, with a bound on the size, and gives the headers in lower case', function (): void {
        $response = (new WpHttpClient)->get(Secret::of('https://api.example/x'), [], 3);

        expect($this->calls)->toBe([['wp_safe_remote_get', 'https://api.example/x', ['timeout' => 10, 'redirection' => 0, 'headers' => [], 'limit_response_size' => 3, 'user-agent' => 'Hornet (WordPress security)']]])
            ->and([$response->status, $response->retryAfter, $response->body])->toBe([201, null, 'abc'])
            ->and($response->headers)->toBe(['x-frame-options' => 'DENY', 'set-cookie' => 'a=1, b=2']);
    });

    it('reaches a private address only when the owner allowed it', function (): void {
        (new WpHttpClient)->post(Secret::of('http://10.0.0.1'), [], '', true);
        (new WpHttpClient)->get(Secret::of('http://10.0.0.1'), [], 10, allowPrivate: true);

        expect(array_column($this->calls, 0))->toBe(['wp_remote_post', 'wp_remote_get']);
    });

    it('takes a Retry-After that is a number of seconds, and no other', function (mixed $header): void {
        Functions\when('wp_remote_retrieve_header')->justReturn($header);

        expect((new WpHttpClient)->post(Secret::of('https://x.example'), [], '')->retryAfter)->toBeNull();
    })->with(['', 'Wed, 21 Oct 2026 07:28:00 GMT', '-1', [[['1', '2']]]]);

    it('fails with the code of the error, and never with the address, which may hold a token', function (string $method): void {
        $token = implode('', ['to', 'ken', '-in-the-address']);

        foreach (['wp_safe_remote_post', 'wp_safe_remote_get'] as $function) {
            Functions\when($function)->justReturn(new FakeWpError('http_request_failed', 'cURL error for https://x.example/'.$token));
        }

        try {
            $method === 'post'
                ? (new WpHttpClient)->post(Secret::of('https://x.example/'.$token), [], '')
                : (new WpHttpClient)->get(Secret::of('https://x.example/'.$token), [], 10);
            $this->fail('No exception.');
        } catch (TransportException $transportException) {
            expect($transportException->getMessage())->toBe('The request failed (http_request_failed).');
        }
    })->with(['post', 'get']);
});

describe('the mail of the site', function (): void {
    it('sends plain text, with no line break left in the subject', function (bool $accepted): void {
        $sent = null;
        Functions\when('wp_mail')->alias(function (array $to, string $subject, string $body, array $headers) use (&$sent, $accepted): bool {
            $sent = [$to, $subject, $body, $headers];

            return $accepted;
        });

        expect((new WpMailer)->send(['a@example.com'], "Alert\r\nBcc: x@example.com\n", 'Body'))->toBe($accepted)
            ->and($sent)->toBe([['a@example.com'], 'Alert Bcc: x@example.com', 'Body', ['Content-Type: text/plain; charset=UTF-8']]);
    })->with([true, false]);
});

describe('the hooks of WordPress', function (): void {
    it('are attached with their priority and number of arguments', function (): void {
        $added = [];

        foreach (['add_action', 'add_filter'] as $function) {
            Functions\when($function)->alias(function (string $hook, callable $callback, int $priority, int $args) use (&$added, $function): bool {
                $added[] = [$function, $hook, $priority, $args];

                return true;
            });
        }

        $hooks = new WordPressHooks;
        $hooks->addAction('init', static fn () => null, 5, 2);
        $hooks->addFilter('the_title', static fn (string $title): string => $title);

        expect($added)->toBe([['add_action', 'init', 5, 2], ['add_filter', 'the_title', 10, 1]]);
    });
});

describe('what is installed', function (): void {
    it('lists the plugins that have a folder and a version, one a folder', function (): void {
        Functions\when('get_plugins')->justReturn([
            'akismet/akismet.php' => ['Name' => 'Akismet', 'Version' => '5.0', 'UpdateURI' => 'https://example.com'],
            'akismet/other.php' => ['Name' => 'Other', 'Version' => '9.9'],
            'hello.php' => ['Name' => 'Hello', 'Version' => '1.0'],
            'dev/dev.php' => ['Name' => 'Dev', 'Version' => ''],
            'tool/tool.php' => ['Name' => 'Tool', 'Version' => '2'],
        ]);

        expect((new WpPluginInventory)->plugins())->toEqual([
            new InstalledPlugin('akismet', '5.0', 'Akismet', 'https://example.com'),
            new InstalledPlugin('tool', '2', 'Tool'),
        ]);
    });

    it('lists the themes but those written for the site, with 0 for a version that is not told', function (): void {
        Functions\when('wp_get_themes')->justReturn(['mine' => new FakeTheme('mine'), 'astra' => new FakeTheme('astra', '4.1'), 'bare' => new FakeTheme('bare', ''), 2024 => new FakeTheme('2024', false)]);

        expect((new WpThemeInventory(['mine']))->plugins())->toEqual([
            new InstalledPlugin('astra', '4.1'),
            new InstalledPlugin('bare', '0'),
            new InstalledPlugin('2024', '0'),
        ]);
    });
});

describe('the texts of the database', function (): void {
    beforeEach(function (): void {
        $this->db = new FakeWpdb;
        $this->store = new WpContentStore($this->db);
    });

    it('reads four sources', function (): void {
        expect($this->store->sources())->toBe(['options', 'posts', 'comments', 'users']);
    });

    it('asks for the next primary key of the table of a source', function (string $source, string $table, string $key): void {
        $this->db->answers = ['12', null];

        expect($this->store->next($source, 5))->toBe(12)
            ->and($this->store->next($source, 12))->toBeNull()
            ->and($this->db->ran[0])->toBe("SELECT MIN(`{$key}`) FROM `{$table}` WHERE `{$key}` > 5");
    })->with([
        ['options', 'wp_options', 'option_id'],
        ['posts', 'wp_posts', 'ID'],
        ['comments', 'wp_comments', 'comment_ID'],
        ['users', 'wp_users', 'ID'],
    ]);

    it('looks for every mark in a range of keys, and leaves out what is not shown or not the site\'s', function (string $source, array $expected): void {
        $this->db->answers = [['3', '9']];

        expect($this->store->matches($source, 1, 100))->toBe([3, 9]);

        $query = $this->db->ran[0];

        expect($query)->toContain('BETWEEN 1 AND 100', "LIKE '%<script%'", "LIKE '%onload%'", ...$expected)
            ->and(substr_count($query, 'USING latin1) LIKE'))->toBe(9);
    })->with([
        ['options', ["option_name NOT LIKE '\\\\_transient\\\\_%'", "option_name NOT LIKE '\\\\_site\\\\_transient\\\\_%'", "option_name NOT LIKE 'hornet\\\\_%'"]],
        ['posts', ["post_type <> 'revision'", "post_status NOT IN ('trash', 'auto-draft')"]],
        ['comments', ["comment_approved = '1'"]],
        ['users', ['CAST(`user_url` AS BINARY)']],
    ]);

    it('hands over the entries by their kind, an option of the identity of the site apart', function (string $source, array $rows, array $expected): void {
        $this->db->answers = [array_map(static fn (array $row): object => (object) $row, $rows)];

        $entries = $this->store->entries($source, [1, 2]);

        expect(array_map(static fn (ContentEntry $entry): array => [$entry->kind->value, $entry->id, $entry->name, $entry->text], $entries))->toBe($expected)
            ->and($this->db->ran[0])->toContain('IN (1,2)', 'CONCAT(LEFT(');
    })->with([
        'options' => ['options', [['id' => '1', 'name' => 'siteurl', 'body' => 'a'], ['id' => '2', 'name' => 'widget_text', 'body' => 'b']], [['site-identity', 1, 'siteurl', 'a'], ['option', 2, 'widget_text', 'b']]],
        'posts' => ['posts', [['id' => '1', 'name' => '1', 'body' => 'p']], [['post', 1, '', 'p']]],
        'comments' => ['comments', [['id' => '2', 'name' => '2', 'body' => 'c']], [['comment', 2, '', 'c']]],
        'users' => ['users', [['id' => '2', 'name' => '2', 'body' => 'u']], [['profile', 2, '', 'u']]],
    ]);

    it('asks nothing for no entry', function (): void {
        expect($this->store->entries('posts', []))->toBe([])->and($this->db->ran)->toBe([]);
    });

    it('never takes a failed query for an empty table: it would pass for a clean one', function (string $method, array $arguments): void {
        $this->db->failWith = 'Lost connection';

        $this->store->{$method}(...$arguments);
    })->throws(ContentReadException::class, 'posts')->with([
        'next' => ['next', ['posts', 0]],
        'matches' => ['matches', ['posts', 1, 10]],
        'entries' => ['entries', ['posts', [1]]],
    ]);
});

describe('the translations', function (): void {
    beforeEach(function (): void {
        (new ReflectionProperty(WpTranslator::class, 'loaded'))->setValue(null, null);
        $this->languages = sys_get_temp_dir().'/hornet-languages-'.bin2hex(random_bytes(4));
        mkdir($this->languages);
        file_put_contents($this->languages.'/pollora-hornet-fr_FR.l10n.php', '<?php return [];');
        $this->loaded = [];
        $this->unloaded = 0;
        $this->locale = 'fr_FR';
        Functions\when('translate')->alias(static fn (string $text, string $domain): string => $domain.':'.$text);
        Functions\when('translate_nooped_plural')->alias(static fn (array $nooped, int $count, string $domain): string => $domain.':'.$count.':'.($count > 1 ? $nooped['plural'] : $nooped['singular']));
        Functions\when('did_action')->justReturn(1);
        Functions\when('determine_locale')->alias(fn (): string => $this->locale);
        Functions\when('unload_textdomain')->alias(function (): bool {
            $this->unloaded++;

            return true;
        });
        Functions\when('load_textdomain')->alias(function (string $domain, string $file): bool {
            $this->loaded[] = [$domain, $file];

            return true;
        });
    });

    afterEach(function (): void {
        unlink($this->languages.'/pollora-hornet-fr_FR.l10n.php');
        rmdir($this->languages);
        (new ReflectionProperty(WpTranslator::class, 'loaded'))->setValue(null, null);
    });

    it('answers with the functions of WordPress, in the domain of the package', function (): void {
        $translator = new WpTranslator($this->languages);

        expect($translator->translate('Never'))->toBe('pollora-hornet:Never')
            ->and($translator->plural('%d day', '%d days', 3))->toBe('pollora-hornet:3:%d days');
    });

    it('loads the file of the language once, and again when the language changes, letting the old one go first', function (): void {
        $translator = new WpTranslator($this->languages);
        $translator->translate('a');
        $translator->translate('b');

        expect($this->loaded)->toBe([['pollora-hornet', $this->languages.'/pollora-hornet-fr_FR.mo']])->and($this->unloaded)->toBe(1);

        $this->locale = 'de_DE';
        $translator->plural('a', 'b', 1);

        // No file for German: the French one is let go, nothing is loaded
        expect($this->loaded)->toHaveCount(1)->and($this->unloaded)->toBe(2);
    });

    it('loads nothing before init: the language is not settled', function (): void {
        Functions\when('did_action')->justReturn(0);
        (new WpTranslator($this->languages))->translate('a');

        expect($this->loaded)->toBe([])->and($this->unloaded)->toBe(0);
    });

    it('finds the translations of the package next to its sources', function (): void {
        WpTranslator::forPackage()->translate('a');

        expect($this->loaded[0][1] ?? null)->toBe(dirname(__DIR__, 3).'/languages/pollora-hornet-fr_FR.mo');
    });
});

describe('the headers of the dashboard and of the login screen', function (): void {
    beforeEach(function (): void {
        Functions\when('is_ssl')->justReturn(true);
        $this->hooks = new RecordingHooks;
        (new HeadersModule(HeaderSettings::fromArray(['content_type_options' => true, 'referrer_policy' => 'same-origin', 'csp' => ['policy' => "default-src 'self'", 'mode' => 'enforce', 'admin' => true]])))->register($this->hooks);
    });

    it('are sent in both, a policy next to the one of WordPress and the others in its place', function (string $hook): void {
        $this->hooks->callback($hook)();

        $sent = [];

        foreach (SentHeaders::$sent as [$line, $replace]) {
            $sent[explode(':', $line)[0]] = $replace;
        }

        expect($sent)->toHaveKey('X-Content-Type-Options')->toHaveKey('Referrer-Policy')->toHaveKey('Content-Security-Policy')
            ->and($sent['X-Content-Type-Options'])->toBeTrue()
            ->and($sent['Content-Security-Policy'])->toBeFalse()
            ->and(array_column(SentHeaders::$sent, 0))->toContain('X-Content-Type-Options: nosniff', 'Referrer-Policy: same-origin');

        foreach ($sent as $name => $replace) {
            expect($replace)->toBe(! str_starts_with($name, 'Content-Security-Policy'));
        }
    })->with(['admin_init', 'login_init']);

    it('are not sent once output has begun', function (): void {
        SentHeaders::$alreadySent = true;
        $this->hooks->callback('admin_init')();

        expect(SentHeaders::$sent)->toBe([]);
    });
});

describe('the channels', function (): void {
    function spec(ChannelType $type, array $options = []): ChannelSpec
    {
        return new ChannelSpec('team', $type, Severity::High, $options);
    }

    it('are built after their type, each with its name and its threshold', function (ChannelType $type, string $class): void {
        $factory = new ChannelFactory(new FakeHttpClient, new FakeMailer, new FakeClock);
        $url = Secret::of('https://example.com/hook');

        $channels = $factory->build([spec($type, ['url' => $url, 'token' => Secret::of('t'), 'secret' => Secret::of('s'), 'chat_id' => '12', 'to' => ['a@example.com']])]);

        expect($channels)->toHaveCount(1)
            ->and($channels[0])->toBeInstanceOf($class)
            ->and($channels[0]->name())->toBe('team');
    })->with([
        [ChannelType::Mail, MailChannel::class],
        [ChannelType::Slack, SlackChannel::class],
        [ChannelType::Teams, TeamsChannel::class],
        [ChannelType::Discord, DiscordChannel::class],
        [ChannelType::Telegram, TelegramChannel::class],
        [ChannelType::Ntfy, NtfyChannel::class],
        [ChannelType::Webhook, WebhookChannel::class],
    ]);

    it('sends where the settings say, and to a private address only when allow_private is literally true', function (ChannelType $type, mixed $allow, bool $private): void {
        $http = new FakeHttpClient;
        $url = 'https://push.example.com/alerts';
        $options = ['url' => Secret::of($url), 'secret' => Secret::of('k')] + ($allow === null ? [] : ['allow_private' => $allow]);

        (new ChannelFactory($http, new FakeMailer, new FakeClock))->channel(spec($type, $options))->send(Samples::notification());

        expect($http->posts)->toHaveCount(1)
            ->and($http->posts[0]['url'])->toBe($type === ChannelType::Ntfy ? 'https://push.example.com/' : $url)
            ->and($http->posts[0]['private'])->toBe($private);
    })->with([ChannelType::Webhook, ChannelType::Ntfy])->with([
        'allowed' => [true, true],
        'not said' => [null, false],
        'the word true' => ['true', false],
        'one' => [1, false],
    ]);

    it('gives ntfy its token only when there is one that is a secret', function (mixed $token, ?string $header): void {
        $http = new FakeHttpClient;
        $options = ['url' => Secret::of('https://push.example.com/alerts'), 'token' => $token];

        (new ChannelFactory($http, new FakeMailer, new FakeClock))->channel(spec(ChannelType::Ntfy, $options))->send(Samples::notification());

        expect($http->posts[0]['headers']['Authorization'] ?? null)->toBe($header);
    })->with([
        'a secret' => [Secret::of('abc'), 'Bearer abc'],
        'none' => [null, null],
        'plain text' => ['abc', null],
    ]);

    it('posts nowhere that was not wrapped as a secret by the settings', function (): void {
        $http = new FakeHttpClient;

        (new ChannelFactory($http, new FakeMailer, new FakeClock))->channel(spec(ChannelType::Slack, ['url' => 'https://plain.example.com']))->send(Samples::notification());

        expect($http->posts[0]['url'])->toBe('');
    });

    it('mails only the recipients that are text', function (mixed $to, array $expected): void {
        $mailer = new FakeMailer;

        (new ChannelFactory(new FakeHttpClient, $mailer, new FakeClock))->channel(spec(ChannelType::Mail, ['to' => $to]))->send(Samples::notification());

        expect($mailer->mails[0]['to'])->toBe($expected);
    })->with([
        'a list with a stray value' => [[5, 'a@example.com', null, 'b@example.com'], ['a@example.com', 'b@example.com']],
        'not a list' => ['a@example.com', []],
    ]);
});

describe('a secret named in the settings', function (): void {
    it('is read from the environment, and is none when empty or not there', function (): void {
        $name = 'HORNET_TEST_'.bin2hex(random_bytes(4));
        $secrets = new EnvironmentSecrets;
        $value = str_repeat('v', 8);

        expect($secrets->env($name))->toBeNull();

        putenv($name.'='.$value);
        expect($secrets->env($name))->toBe($value);

        $_SERVER[$name] = 'server';
        expect($secrets->env($name))->toBe('server');

        $_ENV[$name] = 'env';
        expect($secrets->env($name))->toBe('env');

        $_ENV[$name] = '';
        expect($secrets->env($name))->toBeNull();

        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);
    });

    it('is read from a constant that holds a text', function (): void {
        $name = 'HORNET_TEST_'.strtoupper(bin2hex(random_bytes(4)));
        $secrets = new EnvironmentSecrets;

        expect($secrets->constant($name))->toBeNull();

        define($name, str_repeat('c', 8));
        define($name.'_EMPTY', '');
        define($name.'_NUMBER', 5);

        expect($secrets->constant($name))->toBe('cccccccc')
            ->and($secrets->constant($name.'_EMPTY'))->toBeNull()
            ->and($secrets->constant($name.'_NUMBER'))->toBeNull();
    });
});

describe('the settings of a project', function (): void {
    it('are the configuration of the application when the caller holds none', function (): void {
        Functions\when('config')->alias(static fn (string $key): mixed => $key === 'pollora.hornet' ? ['journal' => ['enabled' => true]] : null);

        expect(SettingsLoader::load()->journal->enabled)->toBeTrue();
    });

    it('are the defaults when the application is not ready or holds nothing that is an array', function (Closure $config): void {
        Functions\when('config')->alias($config);

        expect(SettingsLoader::load()->journal->enabled)->toBeFalse()->and(SettingsLoader::load()->enabled)->toBeTrue();
    })->with([
        'not booted' => [static fn (): never => throw new RuntimeException('not booted')],
        'a string' => [static fn (): string => 'yes'],
        'nothing' => [static fn (): mixed => null],
    ]);

    /*
     * The two constants cannot be undefined again: each is tried in a process of its own.
     */
    it('are HORNET_CONFIG over the application, and off with HORNET_DISABLED', function (): void {
        $script = <<<'PHP'
        require getcwd().'/vendor/autoload.php';
        define('HORNET_CONFIG', ['journal' => ['enabled' => true]]);
        $first = Pollora\Hornet\Infrastructure\Config\SettingsLoader::load(1, ['journal' => ['enabled' => false]]);
        define('HORNET_DISABLED', true);
        $second = Pollora\Hornet\Infrastructure\Config\SettingsLoader::load(1);
        echo var_export($first->journal->enabled, true), ' ', var_export($first->enabled, true), ' ', var_export($second->enabled, true);
        PHP;

        $output = [];
        exec(sprintf('cd %s && %s -r %s 2>&1', escapeshellarg(dirname(__DIR__, 3)), escapeshellarg(PHP_BINARY), escapeshellarg($script)), $output, $code);

        expect($code)->toBe(0)->and(implode("\n", $output))->toBe('true true false');
    });
});

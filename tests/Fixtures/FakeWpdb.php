<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

/**
 * The part of `wpdb` that Hornet uses. `prepare()` substitutes its placeholders, so that a test reads the query that would run;
 * every query is kept, and each read answers with the next value the test queued (nothing queued: null).
 * Not final, and `prepare()` may give null as the real one does: a test can mock it.
 */
class FakeWpdb
{
    public string $prefix = 'wp_';

    public string $options = 'wp_options';

    public string $posts = 'wp_posts';

    public string $comments = 'wp_comments';

    public string $users = 'wp_users';

    public string $last_error = '';

    /** The error that the next read leaves behind. */
    public string $failWith = '';

    /** @var list<string> every query that ran, as prepared */
    public array $ran = [];

    /** @var list<mixed> what the next queries answer, in order */
    public array $answers = [];

    /** @var list<array{table: string, data: array<string, mixed>, format: list<string>}> */
    public array $inserted = [];

    public int|false $insertAnswer = 1;

    public function prepare(string $query, mixed ...$args): ?string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = array_values($args[0]);
        }

        $index = 0;

        return (string) preg_replace_callback('/%%|%[sdi]/', static function (array $match) use (&$index, $args): string {
            if ($match[0] === '%%') {
                return '%';
            }

            if (! array_key_exists($index, $args)) {
                throw new \LogicException('Too few values for the query.');
            }

            $value = $args[$index++];

            return match ($match[0]) {
                '%d' => (string) (int) $value,
                '%i' => '`'.$value.'`',
                default => "'".addslashes((string) $value)."'",
            };
        }, $query);
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_var(string $query): mixed
    {
        return $this->answer($query);
    }

    public function get_col(string $query): mixed
    {
        return $this->answer($query) ?? [];
    }

    public function get_results(string $query, string $output = 'OBJECT'): mixed
    {
        return $this->answer($query);
    }

    public function query(string $query): mixed
    {
        return $this->answer($query);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $format
     */
    public function insert(string $table, array $data, array $format): int|false
    {
        $this->inserted[] = ['table' => $table, 'data' => $data, 'format' => $format];

        return $this->insertAnswer;
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    public function get_blog_prefix(int $site = 0): string
    {
        return $site > 1 ? $this->prefix.$site.'_' : $this->prefix;
    }

    private function answer(string $query): mixed
    {
        $this->ran[] = $query;
        $this->last_error = $this->failWith;

        return array_shift($this->answers);
    }
}

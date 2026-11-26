<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use Pollora\Hornet\Domain\Scan\ContentEntry;
use Pollora\Hornet\Domain\Scan\ContentKind;
use Pollora\Hornet\Domain\Scan\ContentRules;
use Pollora\Hornet\Domain\Scan\ContentStore;
use wpdb;

/**
 * The texts of the database of this site (the main site on a network), read through `$wpdb`. Every query is bounded by a range of
 * primary keys or a short list of them, so none grows with the table.
 *
 * Revisions are left out (they are most of a large posts table and are not shown), and so are the transients, Hornet's own
 * options, and the post meta, where page builders keep scripts on purpose. Serialized values are matched as they are written,
 * never unserialized.
 */
final readonly class WpContentStore implements ContentStore
{
    /** A longer text is read by its start and its end, where an injection is added. */
    private const int HALF = 131072;

    private const array IDENTITY = ['siteurl', 'home', 'blogname', 'blogdescription', 'admin_email'];

    public function __construct(private wpdb $database) {}

    public function sources(): array
    {
        return ['options', 'posts', 'comments', 'users'];
    }

    public function next(string $source, int $after): ?int
    {
        [$table, $key] = $this->table($source);
        $next = $this->database->get_var($this->database->prepare('SELECT MIN(%i) FROM %i WHERE %i > %d', $key, $table, $key, $after));

        return is_numeric($next) ? (int) $next : null;
    }

    public function matches(string $source, int $from, int $to): array
    {
        [$table, $key, $text, $where] = $this->table($source);
        // The bytes seen as latin1: the marks are ASCII, the match still ignores case, and it is about twice as fast as with
        // the Unicode collation of the column (measured on MySQL 8 with 2 GB of posts)
        $like = implode(' OR ', array_fill(0, count(ContentRules::MARKS), 'CONVERT(CAST(%i AS BINARY) USING latin1) LIKE %s'));
        $values = [$key, $table, $key, $from, $to];

        foreach (ContentRules::MARKS as $mark) {
            $values[] = $text;
            $values[] = '%'.$this->database->esc_like($mark).'%';
        }

        // $where and $like are made of fixed words and placeholders: every value goes through prepare()
        $query = "SELECT %i FROM %i WHERE %i BETWEEN %d AND %d{$where} AND ({$like})";
        $ids = $this->database->get_col($this->database->prepare($query, $values));

        return array_map(intval(...), $ids);
    }

    public function entries(string $source, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$table, $key, $text] = $this->table($source);
        $name = $source === 'options' ? 'option_name' : $key;
        $list = implode(',', array_fill(0, count($ids), '%d'));
        $query = "SELECT %i AS id, %i AS name, IF(CHAR_LENGTH(%i) <= %d, %i, CONCAT(LEFT(%i, %d), RIGHT(%i, %d))) AS body FROM %i WHERE %i IN ({$list})";
        $rows = $this->database->get_results($this->database->prepare($query, [$key, $name, $text, 2 * self::HALF, $text, $text, self::HALF, $text, self::HALF, $table, $key, ...$ids]));
        $entries = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $option = $source === 'options' ? (string) $row->name : '';
            $kind = match ($source) {
                'posts' => ContentKind::Post,
                'comments' => ContentKind::Comment,
                'users' => ContentKind::Profile,
                default => in_array($option, self::IDENTITY, true) ? ContentKind::SiteIdentity : ContentKind::Option,
            };
            $entries[] = new ContentEntry($kind, (int) $row->id, $option, (string) $row->body);
        }

        return $entries;
    }

    /**
     * @return array{string, string, string, literal-string} the table, its primary key, the column that is read, and what is left out
     */
    private function table(string $source): array
    {
        return match ($source) {
            'posts' => [$this->database->posts, 'ID', 'post_content', " AND post_type <> 'revision' AND post_status NOT IN ('trash', 'auto-draft')"],
            'comments' => [$this->database->comments, 'comment_ID', 'comment_content', " AND comment_approved = '1'"],
            'users' => [$this->database->users, 'ID', 'user_url', ''],
            default => [$this->database->options, 'option_id', 'option_value', " AND option_name NOT LIKE '\\\\_transient\\\\_%%' AND option_name NOT LIKE '\\\\_site\\\\_transient\\\\_%%' AND option_name NOT LIKE 'hornet\\\\_%%'"],
        };
    }
}

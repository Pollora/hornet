<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WpCli;

use Closure;
use DateInterval;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\EventTypes;
use Pollora\Hornet\Domain\Journal\Journal;
use Pollora\Hornet\Domain\Journal\JournalQuery;
use Pollora\Hornet\Domain\Journal\Source;

/**
 * Read the security journal, empty it of what is old, or remove it.
 */
final readonly class JournalCommand
{
    private const array FORMATS = ['table', 'json', 'csv', 'yaml'];

    /**
     * @param  Closure(): Journal  $journal  built when a command runs
     * @param  Closure(): void  $drop  removes the table and its option
     */
    public function __construct(
        private Closure $journal,
        private Closure $drop,
        private int $retentionDays,
        private Clock $clock,
        private Output $output,
    ) {}

    /**
     * List what the journal recorded, the newest first.
     *
     * ## OPTIONS
     *
     * [--type=<type>]
     * : One type (`login.failed`), or a group with a trailing dot (`login.`, `user.`, `plugin.`, `theme.`, `core.`, `option.`, `hornet.`).
     *
     * [--source=<source>]
     * : web, admin, rest, ajax, xmlrpc, cli or cron.
     *
     * [--user=<id>]
     * : The number of the account that did it.
     *
     * [--days=<days>]
     * : Only the last days.
     *
     * [--limit=<n>]
     * : How many (at most 200).
     * ---
     * default: 50
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp hornet journal list --type=login.failed --days=1
     *     wp hornet journal list --type=user. --source=cli
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function list(array $args = [], array $assoc = []): void
    {
        $format = (string) ($assoc['format'] ?? 'table');
        $source = isset($assoc['source']) ? Source::tryFrom((string) $assoc['source']) : null;
        $type = isset($assoc['type']) ? (string) $assoc['type'] : null;

        if (! in_array($format, self::FORMATS, true)) {
            $this->output->error(sprintf('Unknown format "%s": use %s.', $format, implode(', ', self::FORMATS)));

            return;
        }

        if (isset($assoc['source']) && ! $source instanceof Source) {
            $this->output->error('Unknown source: use web, admin, rest, ajax, xmlrpc, cli or cron.');

            return;
        }

        if ($type !== null && ! EventTypes::exists($type) && ! in_array(rtrim($type, '.'), EventTypes::groups(), true)) {
            $this->output->error(sprintf('Unknown type "%s": use one of %s, or a group with a trailing dot (%s).', $type, implode(', ', EventTypes::all()), implode('., ', EventTypes::groups()).'.'));

            return;
        }

        $since = isset($assoc['days']) && ctype_digit((string) $assoc['days']) ? $this->clock->now()->sub(new DateInterval('P'.(int) $assoc['days'].'D')) : null;
        $events = ($this->journal)()->find(new JournalQuery($type, $source, isset($assoc['user']) && ctype_digit((string) $assoc['user']) ? (int) $assoc['user'] : null, $since, (int) ($assoc['limit'] ?? 50)));

        if ($events === []) {
            $this->output->warning('The journal holds no such event.');

            return;
        }

        $this->output->items(array_map(static fn (Event $event): array => [
            'when' => $event->at->format('Y-m-d H:i:s'),
            'type' => $event->type,
            'source' => $event->source->value,
            'by' => $event->actor !== '' ? $event->actor : ($event->userId > 0 ? '#'.$event->userId : '-'),
            'about' => $event->subject,
            'network' => $event->ip,
            'details' => $event->details === [] ? '' : (string) json_encode($event->details, JSON_UNESCAPED_SLASHES),
        ], $events), ['when', 'type', 'source', 'by', 'about', 'network', 'details'], $format);
    }

    /**
     * Forget what is older than the retention (`journal.retention_days`).
     *
     * ## EXAMPLES
     *
     *     wp hornet journal purge
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function purge(array $args = [], array $assoc = []): void
    {
        $cutoff = $this->clock->now()->sub(new DateInterval('P'.$this->retentionDays.'D'));

        $this->output->success(sprintf('%d events older than %d days were forgotten.', ($this->journal)()->purgeBefore($cutoff), $this->retentionDays));
    }

    /**
     * Remove the journal: its table and the option that says which version it is. Everything it kept is lost.
     *
     * ## OPTIONS
     *
     * --yes
     * : Say that you mean it.
     *
     * ## EXAMPLES
     *
     *     wp hornet journal drop --yes
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $assoc
     */
    public function drop(array $args = [], array $assoc = []): void
    {
        if (! array_key_exists('yes', $assoc)) {
            $this->output->error('This removes the whole journal. Run it again with --yes if you mean it.');

            return;
        }

        ($this->drop)();
        $this->output->success('The journal was removed.');
    }
}

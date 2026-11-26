<?php

declare(strict_types=1);

namespace Pollora\Hornet\Application\Scan;

use Closure;
use DateInterval;
use Pollora\Hornet\Domain\Audit\Finding;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Clock;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\I18n\Message;
use Pollora\Hornet\Domain\Notification\HttpClient;
use Pollora\Hornet\Domain\Notification\Secret;
use Pollora\Hornet\Domain\Scan\Exposure;
use Pollora\Hornet\Domain\Scan\ExposureCatalog;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Domain\Scan\ProbeFiles;

/**
 * Asks the site itself what a visitor would be given: a `.env`, a `.git` folder, a copy of wp-config.php or of the database, a debug log,
 * a listing of the uploads, and, if the owner allows it, whether the server runs PHP that sits in the uploads.
 *
 * The first questions are plain GET requests to fixed addresses of the site: nothing is written. An answer counts only when it is
 * a 200 whose start looks like the file (a site that sends its home page for every unknown address exposes nothing). What the
 * answer holds is never kept nor shown: a finding names the address and what it is, not what it says.
 *
 * The last question writes one small file in the uploads, asks for it, and removes it at once. It is off until the owner turns it on.
 */
final readonly class ExposedFilesTask implements ScanTask
{
    public const string ID = 'exposed-files';

    /** The start of an answer that is read: enough for a signature, too little to hold a secret worth keeping. */
    private const int READ = 8192;

    private const int TIMEOUT = 8;

    /**
     * @param  string  $site  the address of the site (its home), with no trailing slash
     * @param  string  $content  the address of the content folder
     * @param  string  $uploads  the address of the uploads folder
     * @param  ProbeFiles|null  $probeFiles  where the file to ask the server to run is put; null does not ask
     * @param  int|null  $budget  seconds after which the questions left are not asked; null for no limit
     * @param  Closure(): string  $token  a random token (16 hexadecimal characters): a test sets it
     * @param  bool  $laravel  whether to ask for the files of a Laravel application too
     */
    public function __construct(
        private HttpClient $http,
        private string $site,
        private string $content,
        private string $uploads,
        private ?ProbeFiles $probeFiles,
        private Clock $clock,
        private ?int $budget,
        private Closure $token,
        private bool $laravel = false,
    ) {}

    public function id(): string
    {
        return self::ID;
    }

    public function run(?Pins $pinned, bool $repin): TaskResult
    {
        $deadline = $this->budget === null ? null : $this->clock->now()->add(new DateInterval('PT'.$this->budget.'S'));
        $exposed = [];
        $answered = 0;
        $asked = 0;
        $notes = [];

        $catalog = ExposureCatalog::all($this->laravel);

        foreach ($catalog as $index => $exposure) {
            if ($deadline instanceof \DateTimeImmutable && $this->clock->now() > $deadline) {
                $notes[] = Message::of('Not asked (out of time): %d addresses.', count($catalog) - $index);

                break;
            }

            $asked++;

            try {
                $response = $this->http->get(Secret::of($this->url($exposure)), [], self::READ, self::TIMEOUT, true);
            } catch (TransportException) {
                continue;
            }

            $answered++;

            if ($exposure->isExposedBy($response->status, $response->body)) {
                $exposed[] = [$exposure->severity, Message::of('/%s%s answers, and it is %s', $exposure->root === Exposure::CONTENT ? 'wp-content/' : '', $exposure->path, Message::known($exposure->what))];
            }
        }

        if ($answered === 0) {
            return new TaskResult(Finding::unknown(self::ID, Message::of('The site did not answer its own address from the server (DNS, a firewall, a certificate that is not trusted): nothing was asked of it.')));
        }

        if ($answered < $asked) {
            $notes[] = Message::of('%d of the %d addresses did not answer.', $asked - $answered, $asked);
        }

        [$ran, $note] = $this->executionProbe();
        if ($note !== null) {
            $notes[] = $note;
        }

        if ($ran !== null) {
            $exposed[] = [Severity::High, $ran];
        }

        return new TaskResult($this->finding($exposed, $answered, $notes));
    }

    /**
     * @return array{Message|null, Message|null} what was found, and what is to be said when the question could not be asked
     */
    private function executionProbe(): array
    {
        if (! $this->probeFiles instanceof ProbeFiles) {
            return [null, null];
        }

        $token = ($this->token)();
        $name = 'hornet-probe-'.$token.'.php';

        // The file prints the token from its reverse, so that the token is not in its source: a server that sends the file as text cannot be mistaken for one that runs it
        if (! $this->probeFiles->plant($name, "<?php echo strrev('".strrev($token)."');\n")) {
            return [null, Message::of('Not asked: the uploads folder cannot be written to, so whether it runs PHP could not be tried.')];
        }

        try {
            $response = $this->http->get(Secret::of($this->uploads.'/'.$name), [], 256, self::TIMEOUT, true);
        } catch (TransportException) {
            return [null, Message::of('Not asked: the server did not answer for the file put in the uploads.')];
        } finally {
            $this->probeFiles->remove($name);
        }

        return $response->status === 200 && hash_equals($token, trim($response->body))
            ? [Message::of('The server runs PHP that sits in the uploads folder: a file that is uploaded there is run'), null]
            : [null, null];
    }

    private function url(Exposure $exposure): string
    {
        return ($exposure->root === Exposure::CONTENT ? $this->content : $this->site).'/'.$exposure->path;
    }

    /**
     * @param  list<array{Severity, Message}>  $exposed
     * @param  list<Message>  $notes
     */
    private function finding(array $exposed, int $answered, array $notes): Finding
    {
        if ($exposed === []) {
            return Finding::pass(self::ID, Message::of('None of the %d addresses asked gives away a file it should not.', $answered), $notes);
        }

        usort($exposed, static fn (array $a, array $b): int => $b[0]->rank() <=> $a[0]->rank());
        $lines = array_map(static fn (array $one): Message => Message::of('[%s] %s', $one[0]->value, $one[1]), $exposed);

        return Finding::fail(
            self::ID,
            $exposed[0][0],
            Message::plural('%d thing is given away by the site itself.', '%d things are given away by the site itself.', count($exposed), count($exposed)),
            [...$lines, ...$notes],
            Message::of('Remove the file from the web root, or deny it in the web server: `wp hornet server-config nginx`, `apache` or `htaccess` prints the rules (Hornet writes nothing there). A password, a key or a salt that was reachable must be changed: assume that it was read.'),
        );
    }
}

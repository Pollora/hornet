<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Scan\DatabaseScriptsTask;
use Pollora\Hornet\Domain\Audit\Outcome;
use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Scan\Pins;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\WordPress\WpContentStore;

const INJECTED = '<script>eval(atob("ZG9jdW1lbnQud3JpdGUoMSk="))</script>';

/** The task, on the real database of the test site. */
function realDatabaseScan(array $ignored = [], ?int $budget = null): DatabaseScriptsTask
{
    return new DatabaseScriptsTask(new WpContentStore($GLOBALS['wpdb']), new SystemClock, $ignored, $budget);
}

beforeEach(function (): void {
    global $wpdb;

    // Written straight into the tables, as an SQL injection does: WordPress would strip some of it
    $this->posts = [];

    foreach ([
        'bad' => ['publish', 'post', 'Hello '.str_repeat('lorem ipsum ', 100).INJECTED],
        'shout' => ['publish', 'page', '<SCRIPT>String.fromCharCode('.implode(',', range(100, 130)).')</SCRIPT>'],
        'embed' => ['publish', 'post', '<script async src="https://platform.example.com/embed.js"></script>'],
        'revision' => ['inherit', 'revision', INJECTED],
        'trashed' => ['trash', 'post', INJECTED],
        // Longer than what is read: the injection is at the end, where it is added
        'long' => ['publish', 'post', str_repeat('a long text. ', 30000).INJECTED],
    ] as $name => [$status, $type, $content]) {
        $wpdb->insert($wpdb->posts, ['post_content' => $content, 'post_status' => $status, 'post_type' => $type, 'post_title' => $name, 'post_name' => 'hornet-'.$name, 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '']);
        $this->posts[$name] = (int) $wpdb->insert_id;
    }

    add_option('hornet_test_widget', INJECTED, '', false);
    add_option('widget_injected', ['text' => INJECTED], '', false);
    set_transient('some_cache', INJECTED, 60);
    $wpdb->insert($wpdb->comments, ['comment_post_ID' => $this->posts['embed'], 'comment_content' => 'nice <script src="//x.example.com/a.js"></script>', 'comment_approved' => '1']);
    $this->comment = (int) $wpdb->insert_id;
    $wpdb->insert($wpdb->comments, ['comment_post_ID' => $this->posts['embed'], 'comment_content' => INJECTED, 'comment_approved' => '0']);
    $this->pending = (int) $wpdb->insert_id;
});

afterEach(function (): void {
    global $wpdb;

    foreach ($this->posts as $id) {
        $wpdb->delete($wpdb->posts, ['ID' => $id]);
    }

    $wpdb->delete($wpdb->comments, ['comment_ID' => $this->comment]);
    $wpdb->delete($wpdb->comments, ['comment_ID' => $this->pending]);
    delete_option('hornet_test_widget');
    delete_option('widget_injected');
    delete_transient('some_cache');
});

it('finds what was written into the tables, and only where it is shown', function (): void {
    global $wpdb;
    $result = realDatabaseScan()->run(null, false);
    $details = implode("\n", $result->finding->details);

    expect($wpdb->last_error)->toBe('')
        ->and($result->finding->outcome)->toBe(Outcome::Fail)
        ->and($result->finding->severity)->toBe(Severity::High)
        ->and($details)->toContain('post #'.$this->posts['bad'].':', 'post #'.$this->posts['shout'].':', 'post #'.$this->posts['long'].':', 'option widget_injected:', 'comment #'.$this->comment.':')
        ->not->toContain('post #'.$this->posts['embed'].':')
        ->not->toContain('post #'.$this->posts['revision'].':')
        ->not->toContain('post #'.$this->posts['trashed'].':')
        ->not->toContain('comment #'.$this->pending.':')
        ->not->toContain('hornet_test_widget')
        ->not->toContain('_transient_')
        ->not->toContain('atob');
});

it('leaves alone the entries the owner knows', function (): void {
    $details = implode("\n", realDatabaseScan(['post:'.$this->posts['bad'], 'option:widget_injected'])->run(null, false)->finding->details);

    expect($details)->not->toContain('post #'.$this->posts['bad'].':')->not->toContain('widget_injected')->toContain('post #'.$this->posts['long'].':');
});

it('goes on from where a budget stopped it, and ends with the same answer', function (): void {
    $whole = realDatabaseScan()->run(null, false)->finding;
    $task = realDatabaseScan(budget: 0);
    $result = $task->run(null, false);

    for ($runs = 0; $result->unfinished && $runs < 200; $runs++) {
        $result = $task->run(Pins::fromArray($result->pins?->toArray()), false);
    }

    expect($result->unfinished)->toBeFalse()->and($result->finding->details)->toBe($whole->details);
});

it('does not call a table it cannot read an empty one', function (): void {
    global $wpdb;
    $comments = $wpdb->comments;
    $suppressed = $wpdb->suppress_errors(true);
    $wpdb->comments = $wpdb->prefix.'hornet_no_such_table';

    try {
        $finding = realDatabaseScan(['post:'.$this->posts['bad'], 'post:'.$this->posts['shout'], 'post:'.$this->posts['long'], 'option:widget_injected'])->run(null, false)->finding;

        expect($finding->outcome)->toBe(Outcome::Unknown)->and($finding->summary)->toContain('could not be read');
    } finally {
        $wpdb->comments = $comments;
        $wpdb->suppress_errors($suppressed);
    }
});

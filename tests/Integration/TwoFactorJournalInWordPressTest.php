<?php

declare(strict_types=1);

use Pollora\Hornet\Application\Journal\FloodGuard;
use Pollora\Hornet\Application\Journal\JournalRecorder;
use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\JournalQuery;
use Pollora\Hornet\Domain\Network\ClientIp;
use Pollora\Hornet\Infrastructure\Clock\SystemClock;
use Pollora\Hornet\Infrastructure\WordPress\Journal\CacheWindowCounter;
use Pollora\Hornet\Infrastructure\WordPress\Journal\JournalSchema;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TableJournal;
use Pollora\Hornet\Infrastructure\WordPress\Journal\TwoFactorJournalModule;
use Pollora\Hornet\Infrastructure\WordPress\Journal\WpRequestContext;
use Pollora\Hornet\Infrastructure\WordPress\WordPressHooks;

// The metadata hooks, driven by the real Two-Factor plugin that the integration WordPress has active
const SECOND_FACTOR_HOOKS = ['add_user_meta', 'update_user_meta', 'delete_user_meta', 'added_user_meta', 'updated_user_meta', 'deleted_user_meta', 'delete_user', 'wpmu_delete_user', 'two_factor_user_authenticated', 'two_factor_user_revalidated', 'wp_login_failed'];

beforeEach(function (): void {
    require_once ABSPATH.'wp-admin/includes/user.php';
    $this->hooks = [];

    foreach (SECOND_FACTOR_HOOKS as $hook) {
        $this->hooks[$hook] = isset($GLOBALS['wp_filter'][$hook]) ? clone $GLOBALS['wp_filter'][$hook] : null;
    }

    JournalSchema::drop();
    JournalSchema::ensure();
    $this->journal = new TableJournal;
    $recorder = new JournalRecorder($this->journal, new WpRequestContext(static fn (): ClientIp => new ClientIp('203.0.113.50', '203.0.113.50')), new SystemClock);
    (new TwoFactorJournalModule($recorder, new FloodGuard(new CacheWindowCounter, scope: 'journal:second-factor'), static fn (): string => '203.0.113.50'))->register(new WordPressHooks);
    $this->user = wp_insert_user(['user_login' => 'second-factor', 'user_pass' => wp_generate_password(), 'user_email' => 'second-factor@example.test']);
});

afterEach(function (): void {
    wp_delete_user($this->user);

    foreach ($this->hooks as $hook => $saved) {
        $saved === null ? remove_all_actions($hook) : $GLOBALS['wp_filter'][$hook] = $saved;
    }

    JournalSchema::drop();
});

function secondFactorEvents(TableJournal $journal): array
{
    return array_map(static fn (Event $event): string => $event->type.' '.($event->details['method'] ?? ''), array_reverse($journal->find(new JournalQuery('user.', limit: 50))));
}

it('tells what the plugin turns on and off, through its own methods and through the metadata alone', function (): void {
    Two_Factor_Core::enable_provider_for_user($this->user, 'Two_Factor_Email');
    Two_Factor_Core::enable_provider_for_user($this->user, 'Two_Factor_Totp');
    Two_Factor_Core::disable_provider_for_user($this->user, 'Two_Factor_Email');
    // What the plugin's WP-CLI command does
    delete_user_meta($this->user, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY);

    expect(secondFactorEvents($this->journal))->toBe([
        'user.second_factor_enabled email',
        'user.second_factor_enabled totp',
        'user.second_factor_disabled email',
        'user.second_factor_disabled totp',
    ]);
});

it('says nothing when an account that had a method is deleted', function (): void {
    Two_Factor_Core::enable_provider_for_user($this->user, 'Two_Factor_Email');
    wp_delete_user($this->user);

    expect(secondFactorEvents($this->journal))->toBe(['user.second_factor_enabled email']);
});

it('says a login of an account with a method still has a second factor to pass', function (): void {
    expect(TwoFactorJournalModule::isPending(get_userdata($this->user)))->toBeFalse();

    Two_Factor_Core::enable_provider_for_user($this->user, 'Two_Factor_Email');

    expect(TwoFactorJournalModule::isPending(get_userdata($this->user)))->toBeTrue();
});

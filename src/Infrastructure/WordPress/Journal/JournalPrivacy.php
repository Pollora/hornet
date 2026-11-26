<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress\Journal;

use Pollora\Hornet\Domain\Journal\Event;
use Pollora\Hornet\Domain\Journal\Journal;
use Pollora\Hornet\Infrastructure\WordPress\HookRegistrar;
use Pollora\Hornet\Infrastructure\WordPress\Module;

/**
 * The journal in the privacy tools of WordPress (Tools > Export Personal Data and Erase Personal Data), and a paragraph for the privacy policy.
 *
 * Exporting gives a person the events that name them. Erasing does not delete the events (a security journal that forgets what happened
 * is no journal): it takes the person out of them, which leaves "someone logged in from a network, at this time".
 *
 * @internal
 */
final readonly class JournalPrivacy implements Module
{
    private const int PAGE = 100;

    public function __construct(
        private Journal $journal,
        private int $retentionDays,
    ) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addFilter('wp_privacy_personal_data_exporters', $this->exporters(...));
        $hooks->addFilter('wp_privacy_personal_data_erasers', $this->erasers(...));
        $hooks->addAction('admin_init', $this->policy(...));
    }

    /**
     * @return array<mixed>
     */
    public function exporters(mixed $exporters): array
    {
        $exporters = is_array($exporters) ? $exporters : [];
        $exporters['hornet-journal'] = ['exporter_friendly_name' => 'Security journal (Hornet)', 'callback' => $this->export(...)];

        return $exporters;
    }

    /**
     * @return array<mixed>
     */
    public function erasers(mixed $erasers): array
    {
        $erasers = is_array($erasers) ? $erasers : [];
        $erasers['hornet-journal'] = ['eraser_friendly_name' => 'Security journal (Hornet)', 'callback' => $this->erase(...)];

        return $erasers;
    }

    /**
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);

        if ($user === false) {
            return ['data' => [], 'done' => true];
        }

        $events = $this->journal->aboutUser($user->ID, self::PAGE, (max(1, $page) - 1) * self::PAGE);
        $items = [];

        foreach ($events as $event) {
            $data = [
                ['name' => 'When', 'value' => $event->at->format('Y-m-d H:i:s').' UTC'],
                ['name' => 'What', 'value' => $event->type],
                ['name' => 'About', 'value' => $event->subject],
            ];

            // The source and the network are those of whoever did it: the person's only if they did it. Of an event that was done to them, by an
            // administrator or by someone who tried to get in, they are somebody else's data, and are not given.
            if ($event->userId === $user->ID) {
                $data[] = ['name' => 'From', 'value' => $event->source->value];
                $data[] = ['name' => 'Network', 'value' => $event->ip];
            }

            $items[] = ['group_id' => 'hornet-journal', 'group_label' => 'Security journal', 'item_id' => 'hornet-journal-'.($event->id ?? 0), 'data' => $data];
        }

        return ['data' => $items, 'done' => count($events) < self::PAGE];
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        $changed = $user === false ? 0 : $this->journal->anonymizeUser($user->ID);

        return [
            'items_removed' => $changed > 0,
            'items_retained' => $changed > 0,
            'messages' => $changed > 0 ? ['The events of the security journal that name this person were kept, without their number, their login or their address.'] : [],
            'done' => true,
        ];
    }

    /**
     * Suggested text for the privacy policy, in Settings > Privacy.
     */
    public function policy(): void
    {
        if (! function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        wp_add_privacy_policy_content('Hornet (security)', wp_kses_post(sprintf(
            '<h2>%s</h2><p>%s</p>',
            'Security journal',
            sprintf('To protect the site, the security journal keeps, for %d days, who logged in or failed to, who was created, deleted or given a role, what was installed, updated or removed, and which sensitive settings changed. For each event it keeps the time, the account, and the network the request came from, shortened (the last part of an IPv4 address, or all but the first 48 bits of an IPv6 address, is removed). It keeps no password and no name that was typed on a login that matches no account.', $this->retentionDays)
        )));
    }
}

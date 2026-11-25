<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

/**
 * A note, kept for a week, that the login throttle met a request it could not trust: the audit turns it into a finding.
 *
 * It writes at most once an hour for the same reason, so a flood of requests does not become a flood of writes.
 */
final class ThrottleProblemNote
{
    private const int KEEP = 604800;

    private const int REFRESH = 3600;

    public function record(string $reason): void
    {
        $note = get_option(LoginThrottleModule::PROBLEM_OPTION);

        if (is_array($note) && ($note['reason'] ?? null) === $reason && is_int($note['at'] ?? null) && $note['at'] > time() - self::REFRESH) {
            return;
        }

        update_option(LoginThrottleModule::PROBLEM_OPTION, ['reason' => $reason, 'at' => time()], false);
    }

    /**
     * @return 'shared'|'forwarded-untrusted'|null
     */
    public function read(): ?string
    {
        $note = get_option(LoginThrottleModule::PROBLEM_OPTION);

        if (! is_array($note) || ! is_int($note['at'] ?? null) || $note['at'] < time() - self::KEEP) {
            return null;
        }

        return match ($note['reason'] ?? null) {
            'shared' => 'shared',
            'forwarded-untrusted' => 'forwarded-untrusted',
            default => null,
        };
    }
}

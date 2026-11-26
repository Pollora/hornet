<?php

declare(strict_types=1);

namespace Pollora\Hornet\Domain\Scan;

/**
 * The page of a folder name in the WordPress.org directory.
 *
 * A folder name proves nothing: a premium or home-made plugin may use one that WordPress.org gave to something else. Before a
 * word is said about "this plugin", the listing has to be recognised as the plugin that is installed ({@see isSamePlugin()}).
 * The name comes from outside: it is compared, never shown.
 *
 * @internal
 */
final readonly class PluginListing
{
    /** Closing reasons that WordPress.org uses. Anything else is not repeated. */
    public const array REASONS = ['security-issue', 'author-request', 'guideline-violation', 'licensing-trademark-violation', 'merged-into-core', 'unused'];

    /** Where a name stops and its slogan starts: "Yoast SEO – Advanced SEO…". */
    private const string SLOGAN = '/\s+[-–—|]\s+|\s*[:(]\s*/u';

    private function __construct(
        public PluginListingStatus $status,
        public string $name = '',
        public ?string $version = null,
        public ?int $updatedAt = null,
        public ?string $closedOn = null,
        public ?string $reason = null,
    ) {}

    /**
     * @param  int|null  $updatedAt  when the author last published; null when the answer could not be read
     */
    public static function listed(string $name, ?string $version, ?int $updatedAt): self
    {
        return new self(PluginListingStatus::Listed, $name, $version, $updatedAt);
    }

    /**
     * @param  string|null  $closedOn  `YYYY-MM-DD`
     * @param  string|null  $reason  one of {@see REASONS}; null while WordPress.org does not publish it
     */
    public static function closed(string $name, ?string $closedOn, ?string $reason): self
    {
        return new self(PluginListingStatus::Closed, $name, closedOn: $closedOn, reason: in_array($reason, self::REASONS, true) ? $reason : null);
    }

    public static function absent(): self
    {
        return new self(PluginListingStatus::Absent);
    }

    /**
     * Whether the installed plugin is the one that is listed: the same name (a slogan after it set apart), and, when both versions
     * are known, not a version the directory has never reached.
     */
    public function isSamePlugin(InstalledPlugin $plugin): bool
    {
        if ($this->version !== null && version_compare($plugin->version, $this->version, '>')) {
            return false;
        }

        $mine = self::words($plugin->name);
        $theirs = self::words($this->name);

        if ($mine === [] || $theirs === []) {
            return false;
        }

        if (self::words(self::withoutSlogan($plugin->name)) === self::words(self::withoutSlogan($this->name))) {
            return true;
        }

        // "WP Mail SMTP" and "WP Mail SMTP by WPForms": the shorter one, two words at least, starts the longer one
        [$short, $long] = count($mine) <= count($theirs) ? [$mine, $theirs] : [$theirs, $mine];

        return count($short) >= 2 && array_slice($long, 0, count($short)) === $short;
    }

    /**
     * @return array{status: string, name: string, version: ?string, updated_at: ?int, closed_on: ?string, reason: ?string}
     */
    public function toArray(): array
    {
        return ['status' => $this->status->value, 'name' => $this->name, 'version' => $this->version, 'updated_at' => $this->updatedAt, 'closed_on' => $this->closedOn, 'reason' => $this->reason];
    }

    public static function fromArray(mixed $value): ?self
    {
        $status = is_array($value) && is_string($value['status'] ?? null) ? PluginListingStatus::tryFrom($value['status']) : null;

        if (! is_array($value) || ! $status instanceof PluginListingStatus || ! is_string($value['name'] ?? null)) {
            return null;
        }

        return new self(
            $status,
            $value['name'],
            is_string($value['version'] ?? null) ? $value['version'] : null,
            is_int($value['updated_at'] ?? null) ? $value['updated_at'] : null,
            is_string($value['closed_on'] ?? null) ? $value['closed_on'] : null,
            is_string($value['reason'] ?? null) ? $value['reason'] : null,
        );
    }

    private static function withoutSlogan(string $name): string
    {
        return preg_split(self::SLOGAN, html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 2)[0] ?? $name;
    }

    /**
     * @return list<string>
     */
    private static function words(string $name): array
    {
        $plain = mb_strtolower(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'UTF-8');

        return preg_split('/[^\p{L}\p{N}]+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}

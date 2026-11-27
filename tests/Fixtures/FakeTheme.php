<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

/**
 * The part of WP_Theme that Hornet reads.
 */
final class FakeTheme
{
    public static ?self $coreDefault = null;

    public function __construct(
        private readonly string $stylesheet = '',
        private readonly mixed $version = '1.0',
        private readonly bool $exists = true,
    ) {}

    public function get(string $header): mixed
    {
        return $header === 'Version' ? $this->version : '';
    }

    public function get_stylesheet(): string
    {
        return $this->stylesheet;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public static function get_core_default_theme(): self|false
    {
        return self::$coreDefault ?? false;
    }
}

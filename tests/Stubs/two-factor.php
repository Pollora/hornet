<?php

declare(strict_types=1);

// A stand-in for the part of the Two-Factor plugin that Hornet asks: who has a method enabled
if (! class_exists('Two_Factor_Core', false)) {
    class Two_Factor_Core
    {
        /** @var array<int, list<string>> */
        public static array $enabled = [];

        /**
         * @return list<string>
         */
        public static function get_enabled_providers_for_user(int $user): array
        {
            return self::$enabled[$user] ?? [];
        }
    }
}

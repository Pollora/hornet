<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

use JsonException;
use Pollora\Hornet\Domain\Audit\SetAside;
use Pollora\Hornet\Domain\Audit\SetAsideStore;

/**
 * The findings set aside from the screen, in one option of the site (not loaded on every page), as JSON.
 */
final readonly class OptionSetAsideStore implements SetAsideStore
{
    public const string OPTION = 'hornet_set_aside';

    public function all(): array
    {
        $stored = get_option(self::OPTION, '');

        if (! is_string($stored) || $stored === '') {
            return [];
        }

        try {
            $data = json_decode($stored, true, 6, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $all = [];

        foreach (is_array($data) ? $data : [] as $check => $value) {
            $aside = SetAside::fromArray((string) $check, $value);

            if ($aside instanceof SetAside) {
                $all[(string) $check] = $aside;
            }
        }

        return $all;
    }

    public function put(SetAside $aside): void
    {
        $this->save([...$this->all(), $aside->check => $aside]);
    }

    public function remove(string $check): void
    {
        $all = $this->all();
        unset($all[$check]);
        $this->save($all);
    }

    /**
     * @param  array<string, SetAside>  $all
     */
    private function save(array $all): void
    {
        $json = json_encode(array_map(static fn (SetAside $aside): array => $aside->toArray(), $all), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        add_option(self::OPTION, $json, '', false);
        update_option(self::OPTION, $json);
    }
}

<?php

declare(strict_types=1);

namespace Pollora\Hornet\Infrastructure\WordPress;

/**
 * Hornet's settings are wrong: the site must keep running, and the mistake must not stay quiet.
 *
 * Nothing else is attached; Site Health shows one critical test with the message.
 *
 * @internal
 */
final readonly class SettingsErrorModule implements Module
{
    public function __construct(private string $message) {}

    public function register(HookRegistrar $hooks): void
    {
        $hooks->addFilter('site_status_tests', $this->tests(...));
    }

    /**
     * @param  array<string, mixed>  $tests
     * @return array<string, mixed>
     */
    public function tests(array $tests): array
    {
        $direct = is_array($tests['direct'] ?? null) ? $tests['direct'] : [];
        $direct['hornet_settings'] = [
            'label' => 'Hornet: settings',
            'test' => fn (): array => [
                'label' => 'Hornet is off: its settings are wrong',
                'status' => 'critical',
                'badge' => ['label' => 'Security', 'color' => 'red'],
                'description' => '<p>'.esc_html($this->message).'</p>',
                'actions' => '',
                'test' => 'hornet_settings',
            ],
        ];
        $tests['direct'] = $direct;

        return $tests;
    }
}

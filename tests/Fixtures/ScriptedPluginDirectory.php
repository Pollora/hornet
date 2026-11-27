<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Closure;
use Pollora\Hornet\Domain\Exceptions\TransportException;
use Pollora\Hornet\Domain\Scan\PluginDirectory;
use Pollora\Hornet\Domain\Scan\PluginListing;

/**
 * A directory that answers what a test wrote; what it does not know is not on WordPress.org.
 */
final class ScriptedPluginDirectory implements PluginDirectory
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param  array<string, PluginListing|TransportException>  $answers
     * @param  Closure(): mixed|null  $onAsk
     */
    public function __construct(
        public array $answers = [],
        public bool $down = false,
        private readonly ?Closure $onAsk = null,
    ) {}

    public function of(string $slug): PluginListing
    {
        $this->asked[] = $slug;

        if ($this->onAsk instanceof Closure) {
            ($this->onAsk)();
        }

        $answer = $this->down ? new TransportException('down') : ($this->answers[$slug] ?? PluginListing::absent());

        return $answer instanceof TransportException ? throw $answer : $answer;
    }
}

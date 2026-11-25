<?php

declare(strict_types=1);

namespace Pollora\Hornet\Tests\Fixtures;

use Pollora\Hornet\Domain\Audit\Severity;
use Pollora\Hornet\Domain\Notification\Channel;
use Pollora\Hornet\Domain\Notification\Delivery;
use Pollora\Hornet\Domain\Notification\Notification;

/**
 * A channel that answers what the test says and remembers what it was sent.
 */
class FakeChannel implements Channel
{
    /** @var list<Notification> */
    public array $sent = [];

    /** @var list<Delivery> */
    public array $answers = [];

    public function __construct(
        private readonly string $name = 'chat',
        private readonly Severity $min = Severity::Medium,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function minSeverity(): Severity
    {
        return $this->min;
    }

    public function send(Notification $notification): Delivery
    {
        $this->sent[] = $notification;

        return array_shift($this->answers) ?? Delivery::delivered();
    }
}

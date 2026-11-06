<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Counts the times the probe event is handled, so a test proves the relay moves it without handling it.
 */
#[AsMessageHandler(bus: 'event.bus')]
final class OutboxProbeRecordedHandler
{
    private int $callCount = 0;

    public function __invoke(OutboxProbeRecorded $event): void
    {
        ++$this->callCount;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }
}

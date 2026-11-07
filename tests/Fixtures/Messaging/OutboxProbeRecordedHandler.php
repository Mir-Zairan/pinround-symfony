<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Counts the times either probe event is handled, so a test proves the relay moves it without handling it, and a
 * worker on its target transport handles it.
 */
final class OutboxProbeRecordedHandler
{
    private int $callCount = 0;

    #[AsMessageHandler(bus: 'event.bus')]
    public function countOutboxProbe(OutboxProbeRecorded $event): void
    {
        ++$this->callCount;
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function countOutageProbe(OutageProbeRecorded $event): void
    {
        ++$this->callCount;
    }

    public function callCount(): int
    {
        return $this->callCount;
    }
}

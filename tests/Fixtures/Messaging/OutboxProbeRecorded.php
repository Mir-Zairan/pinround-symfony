<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

/**
 * Test-only event, routed to the outbox and relayed to async_notify (messenger.yaml, when@test). No handler.
 */
final readonly class OutboxProbeRecorded
{
    public function __construct(public string $probeId)
    {
    }
}

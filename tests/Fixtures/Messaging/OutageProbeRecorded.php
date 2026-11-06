<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

/**
 * Test-only event, routed to the outbox and relayed to valkey_with_outage, which is down until the test brings it back
 * (messenger.yaml, when@test). OutboxProbeRecordedHandler counts it.
 */
final readonly class OutageProbeRecorded
{
    public function __construct(public string $probeId)
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

/**
 * Test-only event, routed to the outbox and relayed to unreachable_valkey, where every send fails.
 */
final readonly class UnreachableProbeRecorded
{
    public function __construct(public string $probeId)
    {
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messenger;

/**
 * Test-only message: its handler dispatches an InsertTenantProbeLabel stamped for $organizationId with
 * DispatchAfterCurrentBusStamp, so the bus holds it back until the current message is done.
 */
final readonly class QueueTenantProbeInsertAfterCurrentMessage
{
    public function __construct(public string $organizationId, public string $label)
    {
    }
}

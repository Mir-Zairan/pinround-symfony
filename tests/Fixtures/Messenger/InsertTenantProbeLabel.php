<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messenger;

/**
 * Test-only message: its handler writes one row. Handled at once, unless a TransportNamesStamp sends it to a queue.
 */
final readonly class InsertTenantProbeLabel
{
    public function __construct(public string $organizationId, public string $label)
    {
    }
}

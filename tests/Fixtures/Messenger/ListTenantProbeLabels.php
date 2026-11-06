<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messenger;

/**
 * Test-only message: its handler reports what the handler's transaction sees of zz_tenant_probe.
 */
final readonly class ListTenantProbeLabels
{
    public function __construct(public bool $isFailingAfterReading = false)
    {
    }
}

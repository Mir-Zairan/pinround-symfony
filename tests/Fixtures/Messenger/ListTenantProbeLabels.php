<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messenger;

/**
 * Test-only message: its handler reports what the handler's transaction sees of zz_tenant_probe, through the ORM
 * (TenantFilter and row-level security) or, with $isReadingWithSql, through raw SQL (row-level security only).
 */
final readonly class ListTenantProbeLabels
{
    public function __construct(public bool $isFailingAfterReading = false, public bool $isReadingWithSql = false)
    {
    }
}

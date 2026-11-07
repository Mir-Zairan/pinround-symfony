<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Audit;

use App\Audit\Api\AuditLog;

/** Stands in for a module that records audit entries, so the test container keeps the AuditLog a consumer is given. */
final readonly class AuditLogConsumer
{
    public function __construct(public AuditLog $auditLog)
    {
    }
}

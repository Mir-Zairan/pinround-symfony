<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\Audit\Api\AuditEntry;
use App\Audit\Api\AuditEntryForAnotherTenantRefused;
use App\Audit\Api\AuditLog;
use App\SharedKernel\Domain\OrganizationId;

/**
 * An AuditLog that keeps its entries in memory. Like DbalAuditLog it refuses an entry of another organization than
 * the tenant's, and it can fail as a write in a broken transaction does.
 */
final class RecordingAuditLog implements AuditLog
{
    /** @var list<AuditEntry> */
    public array $entries = [];

    public function __construct(private readonly OrganizationId $tenant, private readonly ?\Throwable $failure = null)
    {
    }

    public function record(AuditEntry $entry): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
        if (!$entry->organizationId->equals($this->tenant)) {
            throw new AuditEntryForAnotherTenantRefused();
        }
        $this->entries[] = $entry;
    }
}

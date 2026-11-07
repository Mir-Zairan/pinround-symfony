<?php

declare(strict_types=1);

namespace App\Audit\Api;

use App\SharedKernel\Application\TenantNotSet;

/**
 * The append-only activity log (ADR-0026), for actions that have no domain event, for example an MCP call or an export
 * download. Nothing can change or delete an entry once it is recorded.
 */
interface AuditLog
{
    /**
     * Writes the entry in the caller's open transaction, so it is kept only if the change it describes is.
     *
     * @throws TenantNotSet
     * @throws AuditEntryForAnotherTenantRefused when the entry's organization is not the one in TenantContext
     * @throws \LogicException                   when no transaction is open
     */
    public function record(AuditEntry $entry): void;
}

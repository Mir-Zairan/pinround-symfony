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
     * Writes the entry in the caller's open transaction, so it is kept only if the change it describes is. Its time is
     * the entry's occurredAt, or now when it has none; the month of that time must have its aud_event partition.
     *
     * @throws TenantNotSet
     * @throws AuditEntryForAnotherTenantRefused when the entry's organization is not the one in TenantContext
     * @throws \LogicException                   when no transaction is open, or when Audit's per-action allow-list
     *                                           (ADR-0026) does not list the action or the data does not hold
     *                                           exactly its keys; nothing is written
     */
    public function record(AuditEntry $entry): void;
}

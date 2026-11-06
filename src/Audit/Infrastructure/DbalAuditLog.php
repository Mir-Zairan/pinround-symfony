<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure;

use App\Audit\Api\AuditEntry;
use App\Audit\Api\AuditEntryForAnotherTenantRefused;
use App\Audit\Api\AuditLog;
use App\SharedKernel\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * AuditLog on the aud_event table. pinround_app may only insert and read it; the row-level security policy also
 * refuses a row of another organization than the transaction's tenant.
 */
final readonly class DbalAuditLog implements AuditLog
{
    private const string INSERT = 'INSERT INTO aud_event (id, organization_id, at, actor_kind, actor_user_id, actor_token_id,'
        .' action, target_type, target_id, data, ip_hash, user_agent_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
    ) {
    }

    public function record(AuditEntry $entry): void
    {
        if (!$entry->organizationId->equals($this->tenantContext->require())) {
            throw new AuditEntryForAnotherTenantRefused();
        }
        // TenantMiddleware sets the tenant when a transaction begins; outside one the policy would refuse the row anyway.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('An audit entry is recorded in the transaction of the change it describes; none is open.');
        }

        $id = Uuid::v7();
        $this->connection->executeStatement(
            self::INSERT,
            [
                $id->toRfc4122(),
                $entry->organizationId->toString(),
                // The id's own time, so ordering by id and by at agree.
                $id->getDateTime()->format('Y-m-d H:i:s.uP'),
                $entry->actorKind->value,
                $entry->actorUserId,
                $entry->actorTokenId,
                $entry->action,
                $entry->targetType,
                $entry->targetId,
                // An empty PHP array would encode as a JSON list; the column must hold an object.
                [] === $entry->data ? '{}' : json_encode($entry->data, \JSON_THROW_ON_ERROR),
                $entry->ipHash,
                $entry->userAgentHash,
            ],
            [10 => ParameterType::BINARY, 11 => ParameterType::BINARY],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure;

use App\Audit\Api\AuditEntry;
use App\Audit\Api\AuditEntryForAnotherTenantRefused;
use App\Audit\Api\AuditLog;
use App\Audit\Application\AuditDataAllowList;
use App\SharedKernel\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * AuditLog on the aud_event table. pinround_app may only insert and read it; the row-level security policy also
 * refuses a row of another organization than the transaction's tenant. Every entry's data is checked against
 * AuditDataAllowList first, whichever module writes it.
 */
final readonly class DbalAuditLog implements AuditLog
{
    private const string INSERT = 'INSERT INTO aud_event (id, organization_id, at, actor_kind, actor_user_id, actor_token_id,'
        .' action, target_type, target_id, data, ip_hash, user_agent_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private ClockInterface $clock,
    ) {
    }

    public function record(AuditEntry $entry): void
    {
        AuditDataAllowList::refuseUnlessAllowed($entry->action, $entry->data);
        if (!$entry->organizationId->equals($this->tenantContext->require())) {
            throw new AuditEntryForAnotherTenantRefused();
        }
        // TenantMiddleware sets the tenant when a transaction begins; outside one the policy would refuse the row anyway.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('An audit entry is recorded in the transaction of the change it describes; none is open.');
        }

        // An entry recorded from an event keeps the time of the action, not of its (possibly delayed) handling.
        $at = $entry->occurredAt ?? $this->clock->now();
        // The id carries the same instant as at, so ordering by id and by at agree.
        $id = new UuidV7(UuidV7::generate($at));
        $this->connection->executeStatement(
            self::INSERT,
            [
                $id->toRfc4122(),
                $entry->organizationId->toString(),
                $id->getDateTime()->format('Y-m-d H:i:s.uP'),
                $entry->actorKind->value,
                $entry->actorUserId,
                $entry->actorTokenId,
                $entry->action,
                $entry->targetType,
                $entry->targetId,
                // The object cast keeps an empty or list-shaped array a JSON object; the column must hold one.
                json_encode((object) $entry->data, \JSON_THROW_ON_ERROR),
                $entry->ipHash,
                $entry->userAgentHash,
            ],
            [10 => ParameterType::BINARY, 11 => ParameterType::BINARY],
        );
    }
}

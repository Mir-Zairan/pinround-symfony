<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\TurnWait;
use App\Insight\Api\TurnWaits;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * TurnWaits on ins_ticket_turn's primary key (organization_id, ticket_id); the contract is on {@see TurnWaits}.
 */
final readonly class DbalTurnWaits implements TurnWaits
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function waitOf(TicketId $ticketId): ?TurnWait
    {
        $organizationId = $this->requireTransactionTenant();
        $row = $this->connection->fetchAssociative(
            'SELECT turn_user_id, turn_label, waiting_since FROM ins_ticket_turn WHERE organization_id = ? AND ticket_id = ?',
            [$organizationId, $ticketId->toString()],
        );
        if (false === $row) {
            return null;
        }
        if (!\is_string($row['waiting_since'])) {
            throw new \UnexpectedValueException('A turn row lacks its waiting_since.');
        }

        return new TurnWait(
            \is_string($row['turn_user_id']) ? new UserId($row['turn_user_id']) : null,
            \is_string($row['turn_label']) ? $row['turn_label'] : null,
            new \DateTimeImmutable($row['waiting_since']),
        );
    }

    public function restore(TicketId $ticketId, TurnWait $wait): void
    {
        $organizationId = $this->requireTransactionTenant();
        // IS NOT DISTINCT FROM, because one of the two turn columns is always NULL.
        $this->connection->executeStatement(
            'UPDATE ins_ticket_turn SET waiting_since = ? WHERE organization_id = ? AND ticket_id = ?'
            .' AND turn_user_id IS NOT DISTINCT FROM CAST(? AS uuid) AND turn_label IS NOT DISTINCT FROM CAST(? AS text)',
            [$wait->since->format('Y-m-d H:i:s.uP'), $organizationId, $ticketId->toString(), $wait->userId?->toString(), $wait->label],
        );
    }

    private function requireTransactionTenant(): string
    {
        // TenantMiddleware sets the tenant when a transaction begins; outside one the policy would hide every row.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A turn wait is read and restored in the transaction of the move; none is open.');
        }

        return $this->tenantContext->require()->toString();
    }
}

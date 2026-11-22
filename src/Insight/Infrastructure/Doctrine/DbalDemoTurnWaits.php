<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\DemoTurnWaits;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use Doctrine\DBAL\Connection;

/**
 * DemoTurnWaits on ins_ticket_turn; the contract is on {@see DemoTurnWaits}. Row-level security keeps the update in the
 * transaction's tenant, and the organization in the WHERE matches the table's index.
 */
final readonly class DbalDemoTurnWaits implements DemoTurnWaits
{
    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
    ) {
    }

    public function backdate(TicketId $ticketId, \DateTimeImmutable $waitingSince): void
    {
        // TenantMiddleware sets the tenant when a transaction begins; outside one the policy would hide every row.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A turn wait is set in the seed\'s transaction; none is open.');
        }

        $this->connection->executeStatement(
            'UPDATE ins_ticket_turn SET waiting_since = ? WHERE organization_id = ? AND ticket_id = ?',
            [$waitingSince->format('Y-m-d H:i:sP'), $this->tenantContext->require()->toString(), $ticketId->toString()],
        );
    }
}

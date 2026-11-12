<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\TeamLoadQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

final readonly class DbalTeamLoadQueries implements TeamLoadQueries
{
    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
    ) {
    }

    public function waitingTurnsOf(array $userIds, TicketId $excludedTicketId): array
    {
        // Outside a transaction no tenant is set for row-level security, so the loads would silently count zero.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Waiting turns are read in a transaction under the organization as tenant; none is open.');
        }
        if ([] === $userIds) {
            return [];
        }
        $organizationId = $this->tenantContext->require();

        // One array literal keeps the SQL text the same for any team size; a UserId is a uuid, so needs no quoting.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT turn_user_id, kind, count(*) AS tickets FROM ins_ticket_turn
             WHERE organization_id = ? AND turn_user_id = ANY(?::uuid[]) AND kind IN ('review', 'test') AND ticket_id <> ?
             GROUP BY turn_user_id, kind",
            [
                $organizationId->toString(),
                '{'.implode(',', array_map(static fn (UserId $userId): string => $userId->toString(), $userIds)).'}',
                $excludedTicketId->toString(),
            ],
        );

        $waitingTurns = [];
        foreach ($rows as $row) {
            if (!\is_string($row['turn_user_id']) || !\is_string($row['kind']) || !\is_int($row['tickets'])) {
                throw new \LogicException('A turn count from the database has an unexpected type.');
            }
            $waitingTurns[$row['turn_user_id']][$row['kind']] = $row['tickets'];
        }

        return $waitingTurns;
    }
}

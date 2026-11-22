<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\TurnUserQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * TurnUserQueries on ins_ticket_turn's primary key (organization_id, ticket_id).
 */
final readonly class DbalTurnUserQueries implements TurnUserQueries
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function turnUsersOf(array $ticketIds): array
    {
        if ([] === $ticketIds) {
            return [];
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Turn users are read in a transaction under the organization as tenant; none is open.');
        }

        // One array literal keeps the SQL text the same for any number of ids; a TicketId is a uuid, so needs no quoting.
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT ticket_id, turn_user_id FROM ins_ticket_turn'
            .' WHERE organization_id = CAST(:organization AS uuid) AND ticket_id = ANY(CAST(:tickets AS uuid[]))'
            .' AND turn_user_id IS NOT NULL',
            [
                'organization' => $this->tenantContext->require()->toString(),
                'tickets' => '{'.implode(',', array_map(static fn (TicketId $ticketId): string => $ticketId->toString(), $ticketIds)).'}',
            ],
        );

        $turnUsers = [];
        foreach ($rows as $ticketId => $turnUserId) {
            if (!\is_string($turnUserId)) {
                throw new \LogicException('A turn user id from the database is not a string.');
            }
            $turnUsers[(string) $ticketId] = new UserId($turnUserId);
        }

        return $turnUsers;
    }

    public function ticketIdsWaitingOn(UserId $userId, int $max): array
    {
        if ($max < 1) {
            throw new \InvalidArgumentException('At least one ticket id must be asked for.');
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Turn users are read in a transaction under the organization as tenant; none is open.');
        }

        $ticketIds = $this->connection->fetchFirstColumn(
            'SELECT ticket_id FROM ins_ticket_turn WHERE organization_id = CAST(:organization AS uuid)'
            .' AND turn_user_id = CAST(:user AS uuid) ORDER BY base_score DESC, ticket_id LIMIT '.$max,
            ['organization' => $this->tenantContext->require()->toString(), 'user' => $userId->toString()],
        );

        return array_map(static function (mixed $ticketId): string {
            if (!\is_string($ticketId)) {
                throw new \LogicException('A ticket id from the database is not a string.');
            }

            return $ticketId;
        }, $ticketIds);
    }
}

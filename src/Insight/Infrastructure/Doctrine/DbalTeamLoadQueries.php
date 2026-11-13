<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\SavedTurn;
use App\Insight\Api\TeamLoadQueries;
use App\Insight\Api\TeamLoads;
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
        $this->requireTransaction();
        if ([] === $userIds) {
            return [];
        }
        $organizationId = $this->tenantContext->require();

        $rows = $this->connection->fetchAllAssociative(
            'SELECT turn_user_id, kind, count(*) AS tickets FROM ins_ticket_turn
             WHERE organization_id = ? AND turn_user_id = ANY(?::uuid[]) AND kind IN (?, ?) AND ticket_id <> ?
             GROUP BY turn_user_id, kind',
            [$organizationId->toString(), self::arrayLiteralOf($userIds), self::KIND_REVIEW, self::KIND_TEST, $excludedTicketId->toString()],
        );

        return self::waitingTurnsOfRows($rows);
    }

    public function loadsAndSavedTurnOf(array $userIds, TicketId $ticketId): TeamLoads
    {
        $this->requireTransaction();
        $organizationId = $this->tenantContext->require();

        $rows = $this->connection->fetchAllAssociative(
            'SELECT turn_user_id, turn_label, kind, count(*) AS tickets, false AS is_own FROM ins_ticket_turn
             WHERE organization_id = ? AND turn_user_id = ANY(?::uuid[]) AND kind IN (?, ?) AND ticket_id <> ?
             GROUP BY turn_user_id, turn_label, kind
             UNION ALL
             SELECT turn_user_id, turn_label, kind, 1, true FROM ins_ticket_turn WHERE organization_id = ? AND ticket_id = ?',
            [
                $organizationId->toString(), self::arrayLiteralOf($userIds), self::KIND_REVIEW, self::KIND_TEST, $ticketId->toString(),
                $organizationId->toString(), $ticketId->toString(),
            ],
        );

        $savedTurn = null;
        $loadRows = [];
        foreach ($rows as $row) {
            if (true === $row['is_own']) {
                if (!\is_string($row['kind']) || !(null === $row['turn_user_id'] || \is_string($row['turn_user_id'])) || !(null === $row['turn_label'] || \is_string($row['turn_label']))) {
                    throw new \LogicException('A saved turn from the database has an unexpected type.');
                }
                $savedTurn = new SavedTurn(null === $row['turn_user_id'] ? null : new UserId($row['turn_user_id']), $row['turn_label'], $row['kind']);

                continue;
            }
            $loadRows[] = $row;
        }

        return new TeamLoads(self::waitingTurnsOfRows($loadRows), $savedTurn);
    }

    /** Outside a transaction no tenant is set for row-level security, so the loads would silently count zero. */
    private function requireTransaction(): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Waiting turns are read in a transaction under the organization as tenant; none is open.');
        }
    }

    /**
     * One array literal keeps the SQL text the same for any team size; a UserId is a uuid, so needs no quoting.
     *
     * @param list<UserId> $userIds
     */
    private static function arrayLiteralOf(array $userIds): string
    {
        return '{'.implode(',', array_map(static fn (UserId $userId): string => $userId->toString(), $userIds)).'}';
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, array<string, int>>
     */
    private static function waitingTurnsOfRows(array $rows): array
    {
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

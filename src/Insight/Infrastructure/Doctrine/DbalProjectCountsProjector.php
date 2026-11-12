<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use Doctrine\DBAL\Connection;

/**
 * Keeps ins_project_counts in step with one ticket, for DbalTurnProjector in the transaction of the change.
 * ins_project_count_ticket holds the count row each ticket is in; the write to it locks that ticket's row and returns
 * where it was (PostgreSQL 18's RETURNING old), so two changes of one ticket move its count once each, in turn.
 */
final readonly class DbalProjectCountsProjector
{
    private const string PLACE = 'INSERT INTO ins_project_count_ticket (organization_id, ticket_id, project_id, team_id, visibility, is_done)'
        .' VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (organization_id, ticket_id) DO UPDATE SET project_id = EXCLUDED.project_id,'
        .' team_id = EXCLUDED.team_id, visibility = EXCLUDED.visibility, is_done = EXCLUDED.is_done'
        .' RETURNING old.project_id, old.team_id, old.visibility, old.is_done';
    private const string UNPLACE = 'DELETE FROM ins_project_count_ticket WHERE organization_id = ? AND ticket_id = ?'
        .' RETURNING project_id, team_id, visibility, is_done';
    private const string ADD = 'INSERT INTO ins_project_counts AS stored (organization_id, project_id, team_id, visibility, open_count, done_count)'
        .' VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (organization_id, project_id, team_id, visibility) DO UPDATE'
        .' SET open_count = stored.open_count + EXCLUDED.open_count, done_count = stored.done_count + EXCLUDED.done_count';
    private const string SUBTRACT = 'UPDATE ins_project_counts SET open_count = open_count - ?, done_count = done_count - ?'
        .' WHERE organization_id = ? AND project_id = ? AND team_id = ? AND visibility = ?';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    /**
     * Moves the ticket from the count row it was in to the one it is in now: adds a new ticket, takes out one that
     * no longer counts, and writes nothing when neither changed.
     *
     * @param CountedTicket|null $countedAs where the ticket counts now; null when it counts nowhere (no team, or gone)
     *
     * @throws \LogicException when no transaction is open, before any write, or when the count row the ticket was in is
     *                         missing or a write changed no row; the caller's transaction must then roll back
     */
    public function moveTicket(TicketId $ticketId, ?CountedTicket $countedAs): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A ticket\'s project count is moved in the transaction of the change; none is open.');
        }
        $organizationId = $this->tenantContext->require()->toString();

        $countedBefore = null === $countedAs
            ? $this->connection->fetchAssociative(self::UNPLACE, [$organizationId, $ticketId->toString()])
            : $this->connection->fetchAssociative(self::PLACE, [$organizationId, $ticketId->toString(), $countedAs->projectId, $countedAs->teamId->toString(), $countedAs->visibility, $countedAs->isDone ? 'true' : 'false']);
        $wasCountedAs = false === $countedBefore ? null : self::countedTicketOf($countedBefore);

        if (null !== $wasCountedAs && null !== $countedAs && $wasCountedAs->equals($countedAs)) {
            return;
        }
        if (null !== $wasCountedAs) {
            self::requireOneRow($this->connection->executeStatement(self::SUBTRACT, [
                $wasCountedAs->isDone ? 0 : 1, $wasCountedAs->isDone ? 1 : 0,
                $organizationId, $wasCountedAs->projectId, $wasCountedAs->teamId->toString(), $wasCountedAs->visibility,
            ]), 'The project count row of a ticket it counts is missing.');
        }
        if (null !== $countedAs) {
            self::requireOneRow($this->connection->executeStatement(self::ADD, [
                $organizationId, $countedAs->projectId, $countedAs->teamId->toString(), $countedAs->visibility,
                $countedAs->isDone ? 0 : 1, $countedAs->isDone ? 1 : 0,
            ]), 'A ticket was not added to its project count row.');
        }
    }

    /**
     * @param array<string, mixed> $row the old columns; all null when the ticket was not counted before
     */
    private static function countedTicketOf(array $row): ?CountedTicket
    {
        ['project_id' => $projectId, 'team_id' => $teamId, 'visibility' => $visibility, 'is_done' => $isDone] = $row;
        if (null === $projectId && null === $teamId && null === $visibility && null === $isDone) {
            return null;
        }
        if (!\is_string($projectId) || !\is_string($teamId) || !\is_string($visibility) || !\is_bool($isDone)) {
            throw new \LogicException('A counted ticket from the database has an unexpected type.');
        }

        return new CountedTicket($projectId, new TeamId($teamId), $visibility, $isDone);
    }

    private static function requireOneRow(int|string $changedRows, string $refusal): void
    {
        if (1 !== (int) $changedRows) {
            throw new \LogicException($refusal);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\TicketViewer;
use App\Insight\Api\WaitingTurnCount;
use App\Insight\Api\WaitingTurnCounts;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilitySql;
use Doctrine\DBAL\Connection;

/**
 * One grouped count over ins_ticket_turn by turn user, filtered by the team or project when given and the visibility
 * predicate on the row's copied columns, so no join to tkt_ticket. A team or project filter reads the
 * (organization_id, team_id | project_id, turn_user_id, waiting_since) index.
 */
final readonly class DbalWaitingTurnCounts implements WaitingTurnCounts
{
    private const string TURN_ALIAS = 'turn';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function waitingCountsOf(TicketViewer $viewer, array $userIds, ?TeamId $teamId, ?string $projectId): array
    {
        if (null !== $projectId && !ListFilter::isCanonicalUuid($projectId)) {
            throw new \InvalidArgumentException('Waiting turns are counted in a project given by its canonical lowercase uuid.');
        }
        $counts = [];
        foreach ($userIds as $userId) {
            $counts[$userId->toString()] = new WaitingTurnCount(0, null);
        }
        if ([] === $counts) {
            return [];
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Waiting turns are counted in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if (!$organizationId->equals($viewer->organizationId)) {
            throw new \LogicException('Waiting turns are counted under the viewer\'s own organization as tenant.');
        }

        $visibleTurn = TicketVisibilitySql::of(
            TicketVisibility::for($viewer->organizationId, $viewer->viewerId, $viewer->teamIds, $viewer->isAdmin, $viewer->isTriager ? $viewer->viewerId : null),
            self::TURN_ALIAS,
        );
        // An array literal keeps the SQL text the same for any number of people; a user id is a uuid, so needs no quoting.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT turn.turn_user_id, count(*) AS tickets, min(turn.waiting_since) AS oldest_waiting_since FROM ins_ticket_turn AS turn'
            .' WHERE turn.organization_id = CAST(:organization AS uuid)'
            .(null === $teamId ? '' : ' AND turn.team_id = CAST(:team AS uuid)')
            .(null === $projectId ? '' : ' AND turn.project_id = CAST(:project AS uuid)')
            .' AND turn.turn_user_id = ANY(CAST(:people AS uuid[]))'
            .' AND '.$visibleTurn->sql()
            .' GROUP BY turn.turn_user_id',
            [
                'organization' => $organizationId->toString(),
                ...(null === $teamId ? [] : ['team' => $teamId->toString()]),
                ...(null === $projectId ? [] : ['project' => $projectId]),
                'people' => '{'.implode(',', array_keys($counts)).'}',
                ...$visibleTurn->parameters(),
            ],
        );
        foreach ($rows as $row) {
            if (!\is_string($row['turn_user_id']) || !\array_key_exists($row['turn_user_id'], $counts)
                || !\is_int($row['tickets']) || !\is_string($row['oldest_waiting_since'])) {
                throw new \LogicException('A waiting turn count from the database is not a count and time of an asked person.');
            }
            $counts[$row['turn_user_id']] = new WaitingTurnCount($row['tickets'], new \DateTimeImmutable($row['oldest_waiting_since']));
        }

        return $counts;
    }
}

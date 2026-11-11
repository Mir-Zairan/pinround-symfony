<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Application\Turn\TicketTurnFacts;
use App\Insight\Application\Turn\TicketTurnSource;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketTurnSnapshot;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Api\TicketState;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The ticket, its step and its team's people come from Ticketing's {@see TicketTurnQueries}; how many reviews and tests
 * wait for each developer and tester comes from ins_ticket_turn, in one query for the whole team. A ticket no team owns
 * gets no facts, so no turn row: the turn rules choose among a team's people and fall back to its lead.
 */
final readonly class DbalTicketTurnSource implements TicketTurnSource
{
    public function __construct(
        private TicketTurnQueries $ticketTurnQueries,
        private Connection $connection,
        private TenantContext $tenantContext,
    ) {
    }

    public function factsFor(TicketId $ticketId): ?TicketTurnFacts
    {
        $snapshot = $this->ticketTurnQueries->factsOf($ticketId);
        if (null === $snapshot || null === $snapshot->leadId || null === $snapshot->leadDisplayName) {
            return null;
        }

        $loads = $this->waitingTurnsOf([...$snapshot->developerIds, ...$snapshot->testerIds], $ticketId);
        $team = new TeamFacts(
            $snapshot->leadId,
            $snapshot->leadDisplayName,
            array_map(static fn (UserId $developerId): TeamMemberLoad => new TeamMemberLoad($developerId, openReviews: $loads[$developerId->toString()]['review'] ?? 0), $snapshot->developerIds),
            array_map(static fn (UserId $testerId): TeamMemberLoad => new TeamMemberLoad($testerId, ticketsInTesting: $loads[$testerId->toString()]['test'] ?? 0), $snapshot->testerIds),
        );

        // No organization has a current release yet, and the quick check and questions come later in P03.
        return new TicketTurnFacts($ticketId, $snapshot->projectId, $this->ticketStateOf($snapshot), $team, $snapshot->visibility, isRiskCheck: $snapshot->isRiskCheck);
    }

    private function ticketStateOf(TicketTurnSnapshot $snapshot): TicketState
    {
        return new TicketState(
            $snapshot->stepId,
            $snapshot->coreCode,
            $snapshot->groupCode,
            $snapshot->teamId,
            assigneeId: $snapshot->assigneeId,
            reviewerId: $snapshot->reviewerId,
            testerId: $snapshot->testerId,
            pendingAssigneeId: $snapshot->pendingAssigneeId,
            previousStepId: $snapshot->previousStepId,
            reporterId: $snapshot->reporterId,
            waitingOnUserId: $snapshot->waitingOnUserId,
            waitingOnLabel: $snapshot->waitingOnLabel,
            priority: $snapshot->priority,
            release: $snapshot->release,
        );
    }

    /**
     * The ticket's own row is left out, so refreshing a ticket in review does not count it against its own reviewer.
     *
     * @param list<UserId> $userIds
     *
     * @return array<string, array<string, int>> review and test turns waiting, by user id and kind; a person with
     *                                           none is left out
     */
    private function waitingTurnsOf(array $userIds, TicketId $ticketId): array
    {
        if ([] === $userIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT turn_user_id, kind, count(*) AS tickets FROM ins_ticket_turn
             WHERE organization_id = ? AND turn_user_id IN (?) AND kind IN ('review', 'test') AND ticket_id <> ?
             GROUP BY turn_user_id, kind",
            [
                $this->tenantContext->require()->toString(),
                array_map(static fn (UserId $userId): string => $userId->toString(), $userIds),
                $ticketId->toString(),
            ],
            [1 => ArrayParameterType::STRING],
        );

        $loads = [];
        foreach ($rows as $row) {
            if (!\is_string($row['turn_user_id']) || !\is_string($row['kind']) || !\is_int($row['tickets'])) {
                throw new \LogicException('A turn count from the database has an unexpected type.');
            }
            $loads[$row['turn_user_id']][$row['kind']] = $row['tickets'];
        }

        return $loads;
    }
}

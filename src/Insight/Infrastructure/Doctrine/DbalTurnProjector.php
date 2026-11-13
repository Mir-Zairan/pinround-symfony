<?php

declare(strict_types=1);

namespace App\Insight\Infrastructure\Doctrine;

use App\Insight\Api\TurnProjector;
use App\Insight\Application\Turn\TicketTurnFacts;
use App\Insight\Application\Turn\TicketTurnRow;
use App\Insight\Application\Turn\TicketTurnSource;
use App\Insight\Application\Turn\TurnRowResolver;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * TurnProjector on ins_ticket_turn; the contract is on {@see TurnProjector}. Row-level security keeps the row in the
 * transaction's tenant: another organization's row with the same ticket id is invisible, so it is neither updated
 * nor deleted. Each refresh also moves the ticket's project count (ins_project_counts), done tickets included, since
 * every change of its project, team, visibility or step group refreshes it.
 */
final readonly class DbalTurnProjector implements TurnProjector
{
    // waiting_since: IS NOT DISTINCT FROM, because one of the two turn columns is always NULL.
    private const string UPSERT = 'INSERT INTO ins_ticket_turn AS stored (organization_id, ticket_id, team_id, project_id, grp,'
        .' turn_user_id, turn_label, kind, base_score, waiting_since, check_user_id, visibility, reporter_id, assignee_id,'
        .' pending_assignee_id, reviewer_id, tester_id, waiting_on_user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        .' ON CONFLICT (organization_id, ticket_id) DO UPDATE SET team_id = EXCLUDED.team_id, project_id = EXCLUDED.project_id,'
        .' grp = EXCLUDED.grp, turn_user_id = EXCLUDED.turn_user_id, turn_label = EXCLUDED.turn_label, kind = EXCLUDED.kind,'
        .' base_score = EXCLUDED.base_score,'
        .' waiting_since = CASE WHEN stored.turn_user_id IS NOT DISTINCT FROM EXCLUDED.turn_user_id'
        .' AND stored.turn_label IS NOT DISTINCT FROM EXCLUDED.turn_label THEN stored.waiting_since ELSE EXCLUDED.waiting_since END,'
        .' check_user_id = EXCLUDED.check_user_id, visibility = EXCLUDED.visibility, reporter_id = EXCLUDED.reporter_id,'
        .' assignee_id = EXCLUDED.assignee_id, pending_assignee_id = EXCLUDED.pending_assignee_id,'
        .' reviewer_id = EXCLUDED.reviewer_id, tester_id = EXCLUDED.tester_id, waiting_on_user_id = EXCLUDED.waiting_on_user_id';

    public function __construct(
        private TicketTurnSource $source,
        private TurnRowResolver $resolver,
        private DbalProjectCountsProjector $projectCountsProjector,
        private Connection $connection,
        private TenantContext $tenantContext,
        private ClockInterface $clock,
    ) {
    }

    public function refresh(TicketId $ticketId): ?string
    {
        $organizationId = $this->requireTransactionTenant();

        return $this->write($ticketId, $this->source->factsFor($ticketId), $organizationId);
    }

    public function refreshFrom(TicketTurnSnapshot $facts): ?string
    {
        $organizationId = $this->requireTransactionTenant();

        return $this->write($facts->ticketId, $this->source->factsFrom($facts), $organizationId);
    }

    private function requireTransactionTenant(): string
    {
        // TenantMiddleware sets the tenant when a transaction begins; outside one the policy would hide every row.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A turn row is refreshed in the transaction of the change; none is open.');
        }

        return $this->tenantContext->require()->toString();
    }

    /**
     * @param TicketTurnFacts|null $facts null when the ticket is gone or no team owns it, so its row and count go
     */
    private function write(TicketId $ticketId, ?TicketTurnFacts $facts, string $organizationId): ?string
    {
        $this->projectCountsProjector->moveTicket($ticketId, null === $facts ? null : self::countedTicketOf($facts));
        $row = null === $facts ? null : $this->resolver->rowFor($facts);

        if (null === $row) {
            $this->connection->executeStatement(
                'DELETE FROM ins_ticket_turn WHERE organization_id = ? AND ticket_id = ?',
                [$organizationId, $ticketId->toString()],
            );

            return null;
        }

        $this->connection->executeStatement(self::UPSERT, $this->valuesOf($row, $organizationId));

        return $row->overrideNote;
    }

    /**
     * A ticket with facts always has a team: the source gives none for a ticket no team owns.
     */
    private static function countedTicketOf(TicketTurnFacts $facts): ?CountedTicket
    {
        $teamId = $facts->ticket->teamId;

        return null === $teamId ? null : new CountedTicket($facts->projectId, $teamId, $facts->visibility, 'done' === $facts->ticket->groupCode);
    }

    /**
     * @return list<int|string|null>
     */
    private function valuesOf(TicketTurnRow $row, string $organizationId): array
    {
        $ticket = $row->facts->ticket;

        return [
            $organizationId,
            $row->facts->ticketId->toString(),
            $ticket->teamId?->toString(),
            $row->facts->projectId,
            $ticket->groupCode,
            $row->turnUserId?->toString(),
            $row->turnLabel,
            $row->kind->value,
            $row->baseScore,
            $this->clock->now()->format('Y-m-d H:i:sP'),
            $row->checkUserId?->toString(),
            $row->facts->visibility,
            $row->facts->reporterId()->toString(),
            $ticket->assigneeId?->toString(),
            $ticket->pendingAssigneeId?->toString(),
            $ticket->reviewerId?->toString(),
            $ticket->testerId?->toString(),
            $ticket->waitingOnUserId?->toString(),
        ];
    }
}

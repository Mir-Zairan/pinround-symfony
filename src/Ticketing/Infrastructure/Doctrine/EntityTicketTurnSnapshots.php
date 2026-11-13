<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Application\TicketTurnSnapshots;
use App\Ticketing\Domain\Ticket;
use Doctrine\DBAL\Connection;

/**
 * Builds the snapshot from the ticket's own fields and the step and team lookups DbalTicketTurnQueries uses, so both
 * give the same snapshot for the same ticket.
 */
final readonly class EntityTicketTurnSnapshots implements TicketTurnSnapshots
{
    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private TicketTeamLookup $teamLookup,
    ) {
    }

    public function of(Ticket $ticket): TicketTurnSnapshot
    {
        $this->requireTicketsTenant($ticket);
        $teamId = $ticket->team();

        return self::snapshotOf(
            $ticket,
            $this->teamLookup->groupAndCoreCodeOf($ticket->stepId(), $teamId),
            null === $teamId ? [null, null, [], []] : $this->teamLookup->peopleOf($teamId),
        );
    }

    public function ofTeamTicket(Ticket $ticket, array $teamSteps, array $teamPeople): TicketTurnSnapshot
    {
        $this->requireTicketsTenant($ticket);
        if (null === $ticket->team()) {
            throw new \LogicException('A snapshot from team steps and people is built only for a ticket a team owns.');
        }

        return self::snapshotOf(
            $ticket,
            $this->teamLookup->groupAndCoreCodeAmong($ticket->stepId(), $teamSteps),
            TicketTeamLookup::peopleAmong($teamPeople),
        );
    }

    private function requireTicketsTenant(Ticket $ticket): void
    {
        // Outside a transaction no tenant is set for row-level security, so a removed step would look unknown.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A ticket\'s turn snapshot is built in a transaction under the organization as tenant; none is open.');
        }
        if (!$ticket->organizationId()->equals($this->tenantContext->require())) {
            throw new \LogicException('A ticket\'s turn snapshot is built under its own organization as tenant.');
        }
    }

    /**
     * @param array{string, string|null}                                  $groupAndCoreCode
     * @param array{UserId|null, string|null, list<UserId>, list<UserId>} $people           the lead, their name, the
     *                                                                                      developers and the testers
     */
    private static function snapshotOf(Ticket $ticket, array $groupAndCoreCode, array $people): TicketTurnSnapshot
    {
        [$groupCode, $coreCode] = $groupAndCoreCode;
        [$leadId, $leadDisplayName, $developerIds, $testerIds] = $people;

        return new TicketTurnSnapshot(
            $ticket->id(),
            $ticket->projectId()->toString(),
            $ticket->stepId(),
            $coreCode,
            $groupCode,
            $ticket->team(),
            $leadId,
            $leadDisplayName,
            $developerIds,
            $testerIds,
            $ticket->visibility()->value,
            $ticket->priority()->value,
            $ticket->release(),
            $ticket->reporter(),
            $ticket->assignee(),
            $ticket->pendingAssignee(),
            $ticket->reviewer(),
            $ticket->tester(),
            $ticket->waitingOnUser(),
            $ticket->waitingOnLabel(),
            $ticket->previousStepId(),
            $ticket->isRiskCheck(),
        );
    }
}

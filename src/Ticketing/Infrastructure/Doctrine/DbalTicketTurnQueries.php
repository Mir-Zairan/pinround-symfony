<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Identity\Api\UserQueries;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketTurnSnapshot;
use App\Workflow\Api\StepQueries;
use Doctrine\DBAL\Connection;

/**
 * The ticket is read by its primary key, naming organization_id as well as row-level security. Its step comes from the
 * team's cached steps, and from StepQueries::groupOf for a step they no longer list (removed) or a ticket with no team;
 * such a step gets no core code.
 */
final readonly class DbalTicketTurnQueries implements TicketTurnQueries
{
    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private StepQueries $stepQueries,
        private TeamQueries $teamQueries,
        private UserQueries $userQueries,
    ) {
    }

    public function factsOf(TicketId $ticketId): ?TicketTurnSnapshot
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A ticket\'s turn facts are read in a transaction under the organization as tenant; none is open.');
        }

        $row = $this->connection->fetchAssociative(
            'SELECT '.TurnSnapshotRow::COLUMNS.' FROM tkt_ticket AS ticket WHERE ticket.organization_id = ? AND ticket.id = ?',
            [$this->tenantContext->require()->toString(), $ticketId->toString()],
        );
        if (false === $row) {
            return null;
        }

        $stepId = self::text($row['step_id'], 'step_id');
        $teamId = TurnSnapshotRow::teamIdOf($row);
        [$groupCode, $coreCode] = $this->groupAndCoreCodeOf($stepId, $teamId);
        [$leadId, $leadDisplayName, $developerIds, $testerIds] = null === $teamId ? [null, null, [], []] : $this->peopleOf($teamId);

        return TurnSnapshotRow::snapshotOf($ticketId, $row, $groupCode, $coreCode, $leadId, $leadDisplayName, $developerIds, $testerIds);
    }

    /**
     * @return array{string, string|null} the group code and the core code
     */
    private function groupAndCoreCodeOf(string $stepId, ?TeamId $teamId): array
    {
        foreach (null === $teamId ? [] : $this->stepQueries->stepsFor($teamId) as $step) {
            if ($step->id() === $stepId) {
                return [$step->groupCode(), $step->coreCode()];
            }
        }

        return [$this->stepQueries->groupOf($stepId) ?? throw new \LogicException('A ticket is in a step its organization does not have.'), null];
    }

    /**
     * @return array{UserId, string, list<UserId>, list<UserId>} the lead, their name, the developers and the testers
     */
    private function peopleOf(TeamId $teamId): array
    {
        $leadId = $this->teamQueries->leadOf($teamId) ?? throw new \LogicException('A ticket\'s team is not a team of its organization.');
        $peopleByJob = $this->teamQueries->peopleByJob($teamId);
        $lead = $this->userQueries->byIds([$leadId])[$leadId->toString()] ?? throw new \LogicException('The lead of a ticket\'s team is no user.');

        return [$leadId, $lead->displayName, $peopleByJob['developer'], $peopleByJob['tester']];
    }

    private static function text(mixed $value, string $column): string
    {
        return \is_string($value) ? $value : throw new \LogicException(\sprintf('A ticket %s from the database is not a string.', $column));
    }
}

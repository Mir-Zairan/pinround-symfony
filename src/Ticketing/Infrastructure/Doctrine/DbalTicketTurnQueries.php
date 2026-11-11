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
            "SELECT project_id, step_id, previous_step_id, team_id, visibility, priority, release, reporter_id, assignee_id,
                    pending_assignee_id, reviewer_id, tester_id, waiting_on_user_id, waiting_on_label,
                    'risk' = ANY (check_reasons) AS is_risk_check
             FROM tkt_ticket WHERE organization_id = ? AND id = ?",
            [$this->tenantContext->require()->toString(), $ticketId->toString()],
        );
        if (false === $row) {
            return null;
        }

        $stepId = self::text($row['step_id'], 'step_id');
        $teamId = null === $row['team_id'] ? null : new TeamId(self::text($row['team_id'], 'team_id'));
        [$groupCode, $coreCode] = $this->groupAndCoreCodeOf($stepId, $teamId);
        [$leadId, $leadDisplayName, $developerIds, $testerIds] = null === $teamId ? [null, null, [], []] : $this->peopleOf($teamId);

        return new TicketTurnSnapshot(
            $ticketId,
            self::text($row['project_id'], 'project_id'),
            $stepId,
            $coreCode,
            $groupCode,
            $teamId,
            $leadId,
            $leadDisplayName,
            $developerIds,
            $testerIds,
            self::text($row['visibility'], 'visibility'),
            self::text($row['priority'], 'priority'),
            self::textOrNull($row['release'], 'release'),
            new UserId(self::text($row['reporter_id'], 'reporter_id')),
            self::userIdOrNull($row['assignee_id'], 'assignee_id'),
            self::userIdOrNull($row['pending_assignee_id'], 'pending_assignee_id'),
            self::userIdOrNull($row['reviewer_id'], 'reviewer_id'),
            self::userIdOrNull($row['tester_id'], 'tester_id'),
            self::userIdOrNull($row['waiting_on_user_id'], 'waiting_on_user_id'),
            self::textOrNull($row['waiting_on_label'], 'waiting_on_label'),
            self::textOrNull($row['previous_step_id'], 'previous_step_id'),
            true === $row['is_risk_check'],
        );
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

    private static function textOrNull(mixed $value, string $column): ?string
    {
        return null === $value ? null : self::text($value, $column);
    }

    private static function userIdOrNull(mixed $value, string $column): ?UserId
    {
        return null === $value ? null : new UserId(self::text($value, $column));
    }
}

<?php

declare(strict_types=1);

namespace App\Ticketing\Application\CreateTicket;

use App\Insight\Api\TurnProjector;
use App\Organization\Api\MembershipQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Event\TicketCreated;
use App\Ticketing\Api\ProblemReport;
use App\Ticketing\Api\ReportedTicket;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Application\NextTicketNumber;
use App\Ticketing\Application\OrganizationProjects;
use App\Ticketing\Application\ProjectNotFound;
use App\Ticketing\Application\TicketEvents;
use App\Ticketing\Application\TicketHistory;
use App\Ticketing\Domain\BlankTicketTitle;
use App\Ticketing\Domain\Intake\CheckReason;
use App\Ticketing\Domain\Intake\CheckReasons;
use App\Ticketing\Domain\Intake\DraftReport;
use App\Ticketing\Domain\Intake\ReportContext;
use App\Ticketing\Domain\Priority;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\TicketDescriptionTooLong;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\Tickets;
use App\Ticketing\Domain\TicketTitleTooLong;
use App\Ticketing\Domain\TicketType;
use App\Ticketing\Domain\Visibility;
use App\Workflow\Api\StepQueries;

/**
 * The use case behind {@see TicketCommands::create}, which carries the contract.
 */
final readonly class CreateTicket
{
    private const string BACKLOG = 'backlog';
    private const string TO_DO = 'todo';
    private const int MAX_DUPLICATE_KEYS = 3;

    public function __construct(
        private OrganizationProjects $organizationProjects,
        private MembershipQueries $membershipQueries,
        private StepQueries $stepQueries,
        private NextTicketNumber $nextTicketNumber,
        private Tickets $tickets,
        private TicketHistory $ticketHistory,
        private TurnProjector $turnProjector,
        private TicketEvents $ticketEvents,
        private CheckReasons $checkReasons,
    ) {
    }

    /**
     * @throws ProjectNotFound            when the organization has no project with that id; nothing is saved
     * @throws ProjectHasNoTeam           when no team owns the project; nothing is saved
     * @throws AssigneeNotInTeam          when the assignee is not a lead or member of the project's team; nothing is saved
     * @throws TicketTitleMissing         when nothing is left of the title after trimming
     * @throws TicketTitleOverLimit       when the trimmed title has more than 200 characters
     * @throws TicketDescriptionOverLimit when the description has more than 50,000 characters. For these three, the
     *                                    ticket number is already taken and is given back only when the caller rolls back
     */
    public function create(
        OrganizationId $organizationId,
        UserId $reporter,
        string $projectId,
        string $title,
        string $description,
        string $type,
        string $priority,
        ?UserId $assignee,
        \DateTimeImmutable $reportedAt,
    ): string {
        $ticketType = TicketType::tryFrom($type) ?? throw new \InvalidArgumentException('That ticket type is unknown.');
        $ticketPriority = Priority::tryFrom($priority) ?? throw new \InvalidArgumentException('That priority is unknown.');
        $project = $this->organizationProjects->lockedProjectOf($organizationId, $projectId);
        $team = $project->owningTeam() ?? throw new ProjectHasNoTeam('That project has no team, so it takes no tickets.');
        if (null !== $assignee) {
            $this->refuseAnAssigneeOutside($team, $assignee, $organizationId);
        }
        [$backlogStepId, $toDoStepId] = $this->backlogAndToDoStepIdsOf($team);

        try {
            $ticket = Ticket::report(
                $this->tickets->nextIdentity(),
                $organizationId,
                $project->id(),
                new TicketKey($project->key(), $this->nextTicketNumber->takeFor($project->id())),
                $title,
                $description,
                $ticketType,
                $ticketPriority,
                Visibility::All,
                $reporter,
                $assignee,
                $team,
                $backlogStepId,
                $toDoStepId,
                $reportedAt,
            );
        } catch (BlankTicketTitle $blankTitle) {
            throw new TicketTitleMissing($blankTitle->getMessage(), 0, $blankTitle);
        } catch (TicketTitleTooLong $longTitle) {
            throw new TicketTitleOverLimit($longTitle->getMessage(), 0, $longTitle);
        } catch (TicketDescriptionTooLong $longDescription) {
            throw new TicketDescriptionOverLimit($longDescription->getMessage(), 0, $longDescription);
        }
        $this->tickets->add($ticket);
        $this->ticketHistory->recordCreated($organizationId, $ticket->id(), $reporter, $ticket->stepId(), $reportedAt);
        // Its fallback note comes only from choosing a reviewer (OrderedTurnRules), which no Backlog or To do ticket needs.
        $this->turnProjector->refresh($ticket->id());
        $this->ticketEvents->publish(new TicketCreated(
            $organizationId->toString(),
            $ticket->id()->toString(),
            $project->id()->toString(),
            $team->toString(),
            $reporter->toString(),
            $assignee?->toString(),
        ));

        return $ticket->key()->toString();
    }

    /**
     * A viewer is refused too, as a viewer cannot move the ticket (ADR-0013); a user of another organization has no
     * roles here.
     */
    /**
     * The use case behind {@see TicketCommands::report}, which carries the contract.
     */
    public function report(ProblemReport $report): ReportedTicket
    {
        $ticketType = TicketType::tryFrom($report->type) ?? throw new \InvalidArgumentException('That ticket type is unknown.');
        $ticketPriority = Priority::tryFrom($report->priority) ?? throw new \InvalidArgumentException('That priority is unknown.');
        if (null === $report->teamId && null !== $report->assignee) {
            throw new \InvalidArgumentException('A report with no team has nobody to give it to.');
        }
        if (\count($report->duplicateKeys) > self::MAX_DUPLICATE_KEYS) {
            throw new \InvalidArgumentException('A report shows at most three possible duplicates.');
        }
        $project = $this->organizationProjects->lockedProjectOf($report->organizationId, $report->projectId);
        $projectTeam = $project->owningTeam() ?? throw new ProjectHasNoTeam('That project has no team, so it takes no tickets.');
        if (null !== $report->teamId && !$report->teamId->equals($projectTeam)) {
            throw new ProjectHasNoTeam('That project is not one of the team\'s.');
        }
        if (null !== $report->teamId && null !== $report->assignee) {
            if (!$this->membershipQueries->rolesOf($report->reporter, $report->organizationId)->isInTeam($report->teamId)) {
                throw new AssigneeNotInTeam('Only someone in the team can give the report to a person in it.');
            }
            $this->refuseAnAssigneeOutside($report->teamId, $report->assignee, $report->organizationId);
        }
        // "Not sure" keeps the ticket out of every team; its steps are still the organization's core ones, found
        // through the project's team, which has them all.
        [$backlogStepId, $toDoStepId] = $this->backlogAndToDoStepIdsOf($projectTeam);
        $draft = new DraftReport($report->title, $report->description, $report->pinNotes);
        $reasons = $this->checkReasons->for($draft, new ReportContext(
            hasTeam: null !== $report->teamId,
            pinCount: \count($report->pinNotes),
            wasDuplicateShown: [] !== $report->duplicateKeys,
            aiSaysInfoIsMissing: $report->aiSaysInfoIsMissing,
            aiSaysRisky: $report->aiSaysRisky,
        ));
        $isTeamOnly = $this->checkReasons->isSecuritySensitive($draft);

        try {
            $ticket = Ticket::report(
                $this->tickets->nextIdentity(),
                $report->organizationId,
                $project->id(),
                new TicketKey($project->key(), $this->nextTicketNumber->takeFor($project->id())),
                $report->title,
                $report->description,
                $ticketType,
                $ticketPriority,
                $isTeamOnly ? Visibility::Team : Visibility::All,
                $report->reporter,
                $report->assignee,
                $report->teamId,
                $backlogStepId,
                $toDoStepId,
                $report->reportedAt,
                array_map(static fn (CheckReason $reason): string => $reason->value, $reasons),
            );
        } catch (BlankTicketTitle $blankTitle) {
            throw new TicketTitleMissing($blankTitle->getMessage(), 0, $blankTitle);
        } catch (TicketTitleTooLong $longTitle) {
            throw new TicketTitleOverLimit($longTitle->getMessage(), 0, $longTitle);
        } catch (TicketDescriptionTooLong $longDescription) {
            throw new TicketDescriptionOverLimit($longDescription->getMessage(), 0, $longDescription);
        }
        $this->tickets->add($ticket);
        $this->ticketHistory->recordCreated($report->organizationId, $ticket->id(), $report->reporter, $ticket->stepId(), $report->reportedAt);
        $this->turnProjector->refresh($ticket->id());
        $this->ticketEvents->publish(new TicketCreated(
            $report->organizationId->toString(),
            $ticket->id()->toString(),
            $project->id()->toString(),
            $report->teamId?->toString(),
            $report->reporter->toString(),
            $report->assignee?->toString(),
        ));

        return new ReportedTicket($ticket->key()->toString(), array_map(static fn (CheckReason $reason): string => $reason->value, $reasons), $isTeamOnly);
    }

    private function refuseAnAssigneeOutside(TeamId $team, UserId $assignee, OrganizationId $organizationId): void
    {
        $roles = $this->membershipQueries->rolesOf($assignee, $organizationId);
        if (!$roles->isLeadOf($team) && !$roles->isMemberOf($team)) {
            throw new AssigneeNotInTeam('That person is not a lead or member of the project\'s team, so they cannot take the ticket.');
        }
    }

    /**
     * @return array{string, string}
     */
    private function backlogAndToDoStepIdsOf(TeamId $team): array
    {
        $stepIdsByCoreCode = [];
        foreach ($this->stepQueries->stepsFor($team) as $step) {
            if (null !== $step->coreCode()) {
                $stepIdsByCoreCode[$step->coreCode()] = $step->id();
            }
        }

        return [
            $stepIdsByCoreCode[self::BACKLOG] ?? throw new \LogicException('The organization has no Backlog step.'),
            $stepIdsByCoreCode[self::TO_DO] ?? throw new \LogicException('The organization has no To do step.'),
        ];
    }
}

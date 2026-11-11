<?php

declare(strict_types=1);

namespace App\Ticketing\Application\CreateTicket;

use App\Insight\Api\TurnProjector;
use App\Organization\Api\MembershipQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Event\TicketCreated;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Application\NextTicketNumber;
use App\Ticketing\Application\OrganizationProjects;
use App\Ticketing\Application\ProjectNotFound;
use App\Ticketing\Application\TicketEvents;
use App\Ticketing\Application\TicketHistory;
use App\Ticketing\Domain\BlankTicketTitle;
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

    public function __construct(
        private OrganizationProjects $organizationProjects,
        private MembershipQueries $membershipQueries,
        private StepQueries $stepQueries,
        private NextTicketNumber $nextTicketNumber,
        private Tickets $tickets,
        private TicketHistory $ticketHistory,
        private TurnProjector $turnProjector,
        private TicketEvents $ticketEvents,
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

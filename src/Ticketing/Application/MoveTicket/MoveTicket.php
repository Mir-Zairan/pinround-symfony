<?php

declare(strict_types=1);

namespace App\Ticketing\Application\MoveTicket;

use App\Insight\Api\TeamLoadQueries;
use App\Insight\Api\TurnProjector;
use App\Organization\Api\TeamPeopleQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Event\TicketMoved;
use App\Ticketing\Api\MoveRefused;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Application\TicketEvents;
use App\Ticketing\Application\TicketHistory;
use App\Ticketing\Application\TicketTurnSnapshots;
use App\Ticketing\Domain\InvalidTicketKey;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\Tickets;
use App\Ticketing\Domain\TicketVersionConflict;
use App\Ticketing\Domain\WaitingOn;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\Decision;
use App\Workflow\Api\MoveTarget;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\TransitionPolicy;
use App\Workflow\Api\TurnInputs;

/**
 * The use case behind {@see TicketCommands::move} and {@see TicketCommands::moveTo}, which carry the contract.
 */
final readonly class MoveTicket
{
    private const string NEEDS_INPUT = 'input';
    // Workflow's Verb::GiveItTo, which also chooses the assignee; it comes with P03.T8.
    private const string GIVE_IT_TO = 'give_it_to';

    public function __construct(
        private Tickets $tickets,
        private TicketTurnSnapshots $ticketTurnSnapshots,
        private TeamLoadQueries $teamLoadQueries,
        private TeamPeopleQueries $teamPeopleQueries,
        private RuleQueries $ruleQueries,
        private StepQueries $stepQueries,
        private TransitionPolicy $transitionPolicy,
        private TicketHistory $ticketHistory,
        private TurnProjector $turnProjector,
        private TicketEvents $ticketEvents,
    ) {
    }

    /**
     * @throws TicketNotFound         when the key is malformed or the organization has no ticket with it
     * @throws TicketChangedMeanwhile when the ticket's version is not $expectedVersion, or another request saved it first
     * @throws MoveRefused            when the ticket has no team, the policy refuses the verb, or the verb is give_it_to
     */
    public function move(OrganizationId $organizationId, UserId $actor, string $key, string $verb, int $expectedVersion, \DateTimeImmutable $at): void
    {
        $ticket = $this->loadedTicket($organizationId, $key, $expectedVersion);
        $before = $this->ticketTurnSnapshots->of($ticket);
        $teamId = $before->teamId ?? throw new MoveRefused(null);
        if (self::GIVE_IT_TO === $verb) {
            throw new MoveRefused(null);
        }
        $teamFacts = TurnInputs::teamFacts(
            $before->leadId ?? throw new \LogicException('A ticket with a team has a lead.'),
            $before->leadDisplayName ?? throw new \LogicException('A ticket with a team has a lead.'),
            $before->developerIds,
            $before->testerIds,
            $this->teamLoadQueries->waitingTurnsOf([...$before->developerIds, ...$before->testerIds], $before->ticketId),
        );
        $decision = $this->transitionPolicy->check(
            self::ticketStateOf($before),
            $this->actorInTeam($teamId, $actor),
            $this->ruleQueries->rulesFor($teamId),
            $teamFacts,
            $verb,
        );
        $target = $this->targetStepOf(self::allowedTargetOf($decision), $teamId);
        $waitingOn = self::NEEDS_INPUT === $target->coreCode() ? WaitingOn::ofPerson($ticket->reporter()) : null;

        $this->moveAndRecord($ticket, $before, $actor, $target, $waitingOn, $verb, $at);
    }

    /**
     * @throws TicketNotFound            when the key is malformed or the organization has no ticket with it
     * @throws TicketChangedMeanwhile    when the ticket's version is not $expectedVersion, or another request saved it first
     * @throws MoveRefused               when the ticket has no team, the step is not one of its team, is its step now,
     *                                   the policy refuses it, or a waiting-on is given for a step other than Needs input
     * @throws \InvalidArgumentException when both a person and a label are given, or the label is not one line of at
     *                                   most 100 characters
     */
    public function moveTo(
        OrganizationId $organizationId,
        UserId $actor,
        string $key,
        string $stepId,
        int $expectedVersion,
        ?UserId $waitingOnUserId,
        ?string $waitingOnLabel,
        \DateTimeImmutable $at,
    ): void {
        if (null !== $waitingOnUserId && null !== $waitingOnLabel) {
            throw new \InvalidArgumentException('A ticket waits on a person or a label, not both.');
        }
        $ticket = $this->loadedTicket($organizationId, $key, $expectedVersion);
        $before = $this->ticketTurnSnapshots->of($ticket);
        $teamId = $before->teamId ?? throw new MoveRefused(null);
        $target = $this->stepOfTeam($stepId, $teamId);
        $decision = $this->transitionPolicy->checkMoveTo(
            self::ticketStateOf($before),
            $this->actorInTeam($teamId, $actor),
            $this->ruleQueries->rulesFor($teamId),
            $target,
            $this->stepQueries->moveTargetsFor($teamId),
        );
        self::allowedTargetOf($decision);
        $isNeedsInputStep = self::NEEDS_INPUT === $target->coreCode();
        if (!$isNeedsInputStep && (null !== $waitingOnUserId || null !== $waitingOnLabel)) {
            throw new MoveRefused(null);
        }
        $waitingOn = match (true) {
            !$isNeedsInputStep => null,
            null !== $waitingOnUserId => WaitingOn::ofPerson($waitingOnUserId),
            null !== $waitingOnLabel => WaitingOn::ofLabel($waitingOnLabel),
            // FR-WF-15: with nobody named, the ticket waits on its reporter.
            default => WaitingOn::ofPerson($ticket->reporter()),
        };

        $this->moveAndRecord($ticket, $before, $actor, $target, $waitingOn, null, $at);
    }

    private function loadedTicket(OrganizationId $organizationId, string $key, int $expectedVersion): Ticket
    {
        try {
            $ticketKey = TicketKey::fromString($key);
        } catch (InvalidTicketKey $malformedKey) {
            throw new TicketNotFound('The organization has no ticket with that key.', 0, $malformedKey);
        }
        $ticket = $this->tickets->ofKey($organizationId, $ticketKey) ?? throw new TicketNotFound('The organization has no ticket with that key.');
        if ($ticket->version() !== $expectedVersion) {
            throw new TicketChangedMeanwhile('The ticket was changed after it was read.');
        }

        return $ticket;
    }

    private function moveAndRecord(
        Ticket $ticket,
        TicketTurnSnapshot $before,
        UserId $actor,
        StepView $target,
        ?WaitingOn $waitingOn,
        ?string $verb,
        \DateTimeImmutable $at,
    ): void {
        $fromStepId = $ticket->stepId();
        if ($target->id() === $fromStepId) {
            throw new MoveRefused(null);
        }
        $ticket->move($target->id(), $target->groupCode(), self::NEEDS_INPUT === $target->coreCode(), $waitingOn, $at);
        try {
            $this->tickets->save($ticket);
        } catch (TicketVersionConflict $conflict) {
            throw new TicketChangedMeanwhile('The ticket was changed after it was read.', 0, $conflict);
        }
        $overrideNote = $this->turnProjector->refreshFrom($this->ticketTurnSnapshots->of($ticket));
        $this->ticketHistory->recordMoved($ticket->organizationId(), $ticket->id(), $actor, $fromStepId, $target->id(), $verb, $overrideNote, $at);
        $this->ticketEvents->publish(new TicketMoved(
            $ticket->organizationId()->toString(),
            $before->ticketId->toString(),
            $ticket->key()->toString(),
            $fromStepId,
            $target->id(),
            $verb,
            $actor->toString(),
            $at,
        ));
    }

    private static function allowedTargetOf(Decision $decision): MoveTarget
    {
        if (!$decision->isAllowed || null === $decision->moveTarget) {
            throw new MoveRefused($decision->ruleNote?->template, $decision->ruleNote->people ?? []);
        }

        return $decision->moveTarget;
    }

    /**
     * A core target is the organization's step of that code; a step target, such as the step the ticket came from, must
     * still be one of the team's steps, as a removed step takes no new moves.
     */
    private function targetStepOf(MoveTarget $target, TeamId $teamId): StepView
    {
        foreach ($this->stepQueries->stepsFor($teamId) as $step) {
            if ($target->isCoreStep() ? $step->coreCode() === $target->coreCode : $step->id() === $target->stepId) {
                return $step;
            }
        }

        throw new MoveRefused(null);
    }

    private function stepOfTeam(string $stepId, TeamId $teamId): StepView
    {
        foreach ($this->stepQueries->stepsFor($teamId) as $step) {
            if (strtolower($stepId) === $step->id()) {
                return $step;
            }
        }

        throw new MoveRefused(null);
    }

    /**
     * Org roles grant no move, and viewers are not listed, so both get no team role.
     */
    private function actorInTeam(TeamId $teamId, UserId $actor): ActorInTeam
    {
        foreach ($this->teamPeopleQueries->namedPossibleAssigneesOf($teamId) as $person) {
            if ($person->userId->equals($actor)) {
                return new ActorInTeam(
                    $actor,
                    $person->isLead ? ActorInTeam::TEAM_ROLE_LEAD : ActorInTeam::TEAM_ROLE_MEMBER,
                    $person->job->value,
                );
            }
        }

        return new ActorInTeam($actor);
    }

    private static function ticketStateOf(TicketTurnSnapshot $snapshot): TicketState
    {
        return TurnInputs::ticketState(
            stepId: $snapshot->stepId,
            coreCode: $snapshot->coreCode,
            groupCode: $snapshot->groupCode,
            teamId: $snapshot->teamId,
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
}

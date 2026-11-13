<?php

declare(strict_types=1);

namespace App\Ticketing\Application\MoveTicket;

use App\Insight\Api\TeamLoadQueries;
use App\Insight\Api\TurnProjector;
use App\Organization\Api\MembershipQueries;
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
use App\Ticketing\Api\WaitingOnRefused;
use App\Ticketing\Application\TicketEvents;
use App\Ticketing\Application\TicketHistory;
use App\Ticketing\Application\TicketTurnSnapshots;
use App\Ticketing\Domain\InvalidTicketKey;
use App\Ticketing\Domain\InvalidWaitingOn;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\Tickets;
use App\Ticketing\Domain\TicketVersionConflict;
use App\Ticketing\Domain\WaitingOn;
use App\Workflow\Api\Decision;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TransitionPolicy;
use App\Workflow\Api\TurnInputs;
use App\Workflow\Api\TurnPeopleChoice;

/**
 * The use case behind {@see TicketCommands::move} and {@see TicketCommands::moveTo}, which carry the contract.
 */
final readonly class MoveTicket
{
    private const string NEEDS_INPUT = 'input';
    private const string REVIEW = 'review';
    private const string TEST = 'test';
    // Workflow's Verb::GiveItTo, which also chooses the assignee; it comes with P03.T8.
    private const string GIVE_IT_TO = 'give_it_to';

    public function __construct(
        private Tickets $tickets,
        private TicketTurnSnapshots $ticketTurnSnapshots,
        private TeamLoadQueries $teamLoadQueries,
        private TeamPeopleQueries $teamPeopleQueries,
        private MembershipQueries $membershipQueries,
        private RuleQueries $ruleQueries,
        private StepQueries $stepQueries,
        private TransitionPolicy $transitionPolicy,
        private TicketHistory $ticketHistory,
        private TurnProjector $turnProjector,
        private TicketEvents $ticketEvents,
        private TurnPeopleChoice $turnPeopleChoice,
    ) {
    }

    /**
     * @throws TicketNotFound         when the key is malformed or the organization has no ticket with it
     * @throws TicketChangedMeanwhile when the ticket's version is not $expectedVersion, or another request saved it first
     * @throws MoveRefused            when the ticket has no team, the policy refuses the verb, the verb is give_it_to, or
     *                                it leads to Needs input and the reporter is no member of the organization
     */
    public function move(OrganizationId $organizationId, UserId $actor, string $key, string $verb, int $expectedVersion, \DateTimeImmutable $at): void
    {
        $ticket = $this->loadedTicket($organizationId, $key, $expectedVersion);
        $teamId = $ticket->team() ?? throw new MoveRefused(null);
        if (self::GIVE_IT_TO === $verb) {
            throw new MoveRefused(null);
        }
        $team = $this->teamOf($teamId);
        $before = $this->ticketTurnSnapshots->ofTeamTicket($ticket, $team->steps, $team->people);
        $waitingTurns = $this->waitingTurnsOf($before);
        $teamFacts = self::teamFactsOf($before, $waitingTurns);
        $decision = $this->transitionPolicy->check(
            TurnInputs::ticketStateOf($before),
            $team->actorInTeam($actor),
            $this->ruleQueries->rulesFor($teamId),
            $teamFacts,
            $verb,
        );
        self::refuseUnlessAllowed($decision);
        $target = $team->targetStepOf($decision->moveTarget ?? throw new \LogicException('An allowed decision has a move target.'));
        $waitingOn = self::NEEDS_INPUT === $target->coreCode() ? $this->waitingOnReporter($ticket) : null;

        $this->moveAndRecord($ticket, $team, $before, $actor, $target, $waitingOn, $verb, $at, $waitingTurns, $teamFacts);
    }

    /**
     * @throws TicketNotFound         when the key is malformed or the organization has no ticket with it
     * @throws TicketChangedMeanwhile when the ticket's version is not $expectedVersion, or another request saved it first
     * @throws MoveRefused            when the ticket has no team, the step is not one of its team, is its step now,
     *                                the policy refuses it, a waiting-on is given for a step other than Needs input,
     *                                or the person to wait on (the reporter when nobody is named) is no member of the
     *                                organization
     * @throws WaitingOnRefused       when both a person and a label are given, or the label is not one line of at
     *                                most 100 characters
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
            throw new WaitingOnRefused('A ticket waits on a person or a label, not both.');
        }
        $ticket = $this->loadedTicket($organizationId, $key, $expectedVersion);
        $teamId = $ticket->team() ?? throw new MoveRefused(null);
        $team = $this->teamOf($teamId);
        $before = $this->ticketTurnSnapshots->ofTeamTicket($ticket, $team->steps, $team->people);
        $target = $team->stepOfId($stepId);
        $decision = $this->transitionPolicy->checkMoveTo(
            TurnInputs::ticketStateOf($before),
            $team->actorInTeam($actor),
            $this->ruleQueries->rulesFor($teamId),
            $target,
            $this->stepQueries->moveTargetsFor($teamId),
        );
        self::refuseUnlessAllowed($decision);
        $isNeedsInputStep = self::NEEDS_INPUT === $target->coreCode();
        if (!$isNeedsInputStep && (null !== $waitingOnUserId || null !== $waitingOnLabel)) {
            throw new MoveRefused(null);
        }
        if ($isNeedsInputStep && null !== $waitingOnUserId) {
            $this->refuseUnlessMember($waitingOnUserId, $organizationId);
        }
        $waitingOn = match (true) {
            !$isNeedsInputStep => null,
            null !== $waitingOnUserId => WaitingOn::ofPerson($waitingOnUserId),
            null !== $waitingOnLabel => self::waitingOnLabel($waitingOnLabel),
            default => $this->waitingOnReporter($ticket),
        };
        $waitingTurns = $this->waitingTurnsOf($before);

        $this->moveAndRecord($ticket, $team, $before, $actor, $target, $waitingOn, null, $at, $waitingTurns, self::teamFactsOf($before, $waitingTurns));
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function waitingTurnsOf(TicketTurnSnapshot $before): array
    {
        return $this->teamLoadQueries->waitingTurnsOf([...$before->developerIds, ...$before->testerIds], $before->ticketId);
    }

    /**
     * @param array<string, array<string, int>> $waitingTurns
     */
    private static function teamFactsOf(TicketTurnSnapshot $before, array $waitingTurns): TeamFacts
    {
        return TurnInputs::teamFacts(
            $before->leadId ?? throw new \LogicException('A ticket with a team has a lead.'),
            $before->leadDisplayName ?? throw new \LogicException('A ticket with a team has a lead.'),
            $before->developerIds,
            $before->testerIds,
            $waitingTurns,
        );
    }

    /**
     * Only the label the caller sent becomes WaitingOnRefused; an InvalidWaitingOn from Ticket::move would be a bug here,
     * so it stays a fault.
     *
     * @throws WaitingOnRefused when the label is blank, over 100 characters or not one line
     */
    private static function waitingOnLabel(string $label): WaitingOn
    {
        try {
            return WaitingOn::ofLabel($label);
        } catch (InvalidWaitingOn $invalidLabel) {
            throw new WaitingOnRefused($invalidLabel->getMessage(), 0, $invalidLabel);
        }
    }

    /**
     * FR-WF-15: with nobody named, the ticket waits on its reporter, who must still be a member as a named person must.
     *
     * @throws MoveRefused when the reporter is no member of the ticket's organization
     */
    private function waitingOnReporter(Ticket $ticket): WaitingOn
    {
        $this->refuseUnlessMember($ticket->reporter(), $ticket->organizationId());

        return WaitingOn::ofPerson($ticket->reporter());
    }

    private function teamOf(TeamId $teamId): MoveTeam
    {
        return new MoveTeam($this->stepQueries->stepsFor($teamId), $this->teamPeopleQueries->namedPossibleAssigneesOf($teamId));
    }

    /**
     * tkt_ticket.waiting_on_user_id has no foreign key, and who a ticket waits on may see it, so a person of another
     * organization, or one who has left this one, is refused here. Membership alone counts, as a member with no org or
     * team role may still be asked.
     */
    private function refuseUnlessMember(UserId $userId, OrganizationId $organizationId): void
    {
        if (!array_any($this->membershipQueries->organizationsOf($userId), static fn (OrganizationId $memberOf): bool => $memberOf->equals($organizationId))) {
            throw new MoveRefused(null);
        }
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

    /**
     * The reviewer or tester is stored on entering Code review or Testing (FR-WF-14), chosen from the same loads the
     * turn refresh gets, so the stored person, the turn row and who may approve are one person.
     *
     * @param array<string, array<string, int>> $waitingTurns the team's waiting reviews and tests already read for $before
     * @param TeamFacts                         $teamFacts    the team facts built from $before and $waitingTurns
     */
    private function moveAndRecord(
        Ticket $ticket,
        MoveTeam $team,
        TicketTurnSnapshot $before,
        UserId $actor,
        StepView $target,
        ?WaitingOn $waitingOn,
        ?string $verb,
        \DateTimeImmutable $at,
        array $waitingTurns,
        TeamFacts $teamFacts,
    ): void {
        $fromStepId = $ticket->stepId();
        if ($target->id() === $fromStepId) {
            throw new MoveRefused(null);
        }
        $ticket->move(
            $target->id(),
            $target->groupCode(),
            self::NEEDS_INPUT === $target->coreCode(),
            $waitingOn,
            $at,
            self::REVIEW === $target->coreCode() ? $this->turnPeopleChoice->reviewerToStore($before->assigneeId, $teamFacts) : null,
            self::TEST === $target->coreCode() ? $this->turnPeopleChoice->testerToStore($teamFacts) : null,
        );
        try {
            $this->tickets->save($ticket);
        } catch (TicketVersionConflict $conflict) {
            throw new TicketChangedMeanwhile('The ticket was changed after it was read.', 0, $conflict);
        }
        $overrideNote = $this->turnProjector->refreshFrom($this->ticketTurnSnapshots->ofTeamTicket($ticket, $team->steps, $team->people), $waitingTurns);
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

    private static function refuseUnlessAllowed(Decision $decision): void
    {
        if (!$decision->isAllowed) {
            throw new MoveRefused($decision->ruleNote?->template, $decision->ruleNote->people ?? []);
        }
    }
}

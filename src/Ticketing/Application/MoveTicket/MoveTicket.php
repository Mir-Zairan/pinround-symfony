<?php

declare(strict_types=1);

namespace App\Ticketing\Application\MoveTicket;

use App\Insight\Api\TeamLoadQueries;
use App\Insight\Api\TurnProjector;
use App\Insight\Api\TurnWait;
use App\Insight\Api\TurnWaits;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\TeamPeopleQueries;
use App\SharedKernel\Application\PingClearing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\DraftChoice;
use App\Ticketing\Api\DraftDecisionNeeded;
use App\Ticketing\Api\Event\TicketAssigned;
use App\Ticketing\Api\Event\TicketMoved;
use App\Ticketing\Api\MoveFacts;
use App\Ticketing\Api\MoveRefused;
use App\Ticketing\Api\MoveResult;
use App\Ticketing\Api\ShareDraftQuestion;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\WaitingOnRefused;
use App\Ticketing\Application\TicketEvents;
use App\Ticketing\Application\TicketHistory;
use App\Ticketing\Application\TicketTurnSnapshots;
use App\Ticketing\Domain\InvalidTicketKey;
use App\Ticketing\Domain\InvalidWaitingOn;
use App\Ticketing\Domain\MovedFrom;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\Tickets;
use App\Ticketing\Domain\TicketVersionConflict;
use App\Ticketing\Domain\WaitingOn;
use App\Workflow\Api\Decision;
use App\Workflow\Api\MoveToUndo;
use App\Workflow\Api\RuleQueries;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TransitionPolicy;
use App\Workflow\Api\TurnInputs;
use App\Workflow\Api\TurnPeopleChoice;
use App\Workflow\Api\TurnRules;

/**
 * The use case behind {@see TicketCommands::move} and {@see TicketCommands::moveTo}, which carry the contract.
 */
final readonly class MoveTicket
{
    /** Workflow's Verb::GiveItTo, which also names the assignee, so only {@see self::giveTo} takes it. */
    public const string GIVE_IT_TO = 'give_it_to';
    /** The refusal of a person to give the ticket to who is not its team's lead or a member (a viewer, or nobody of it). */
    public const string ASSIGNEE_NOT_IN_TEAM_NOTE = 'Give it to someone in the ticket’s team.';
    /** The refusal of giving the ticket to the person who already has it, with {assignee} for them. */
    public const string ALREADY_GIVEN_NOTE = '{assignee} already has this ticket.';
    /** The verb of an Undo's "moved" row and TicketMoved; not one of Workflow's verbs, as no button but Undo makes it. */
    public const string UNDO = 'undo';

    private const string NEEDS_INPUT = 'input';
    private const string REVIEW = 'review';
    private const string TEST = 'test';

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
        private PingClearing $pingClearing,
        private ShareDraftQuestion $shareDraftQuestion,
        private TurnRules $turnRules,
        private TurnWaits $turnWaits,
    ) {
    }

    /**
     * @return MoveResult the pings the move cleared and what it did with the actor's draft round
     *
     * @throws TicketNotFound         when the key is malformed or the organization has no ticket with it
     * @throws TicketChangedMeanwhile when the ticket's version is not $expectedVersion, or another request saved it first,
     *                                or the actor's draft first; in that last case the move is written, so the caller
     *                                rolls back
     * @throws MoveRefused            when the ticket has no team, the policy refuses the verb, the verb is give_it_to
     *                                (which {@see self::giveTo} takes), or it leads to Needs input and the reporter is no
     *                                member of the organization; or $draftChoice is Share and a shared round stays open
     *                                after the move's close, when the move is written, so the caller rolls back
     * @throws DraftDecisionNeeded    when $draftChoice is null and the actor has an undecided draft on the ticket
     */
    public function move(OrganizationId $organizationId, UserId $actor, string $key, string $verb, int $expectedVersion, \DateTimeImmutable $at, ?DraftChoice $draftChoice = DraftChoice::Keep): MoveResult
    {
        $ticket = $this->loadedTicket($organizationId, $key, $expectedVersion);
        $teamId = $ticket->team() ?? throw new MoveRefused(null);
        if (self::GIVE_IT_TO === $verb) {
            throw new MoveRefused(null);
        }
        $team = $this->teamOf($teamId);
        $allowed = $this->allowedVerbMoveOf($ticket, $teamId, $team, $actor, $verb);
        $waitingOn = self::NEEDS_INPUT === $allowed->target->coreCode() ? $this->waitingOnReporter($ticket) : null;

        return $this->moveAndRecord($ticket, $team, $allowed->before, $actor, $allowed->target, $waitingOn, $verb, $at, $allowed->waitingTurns, $allowed->teamFacts, $allowed->isLeadOnlyMove, $draftChoice);
    }

    /**
     * "Give it to…": the give_it_to verb with the person it names. From Backlog the ticket also goes to To do in the same
     * save (FR-WF-16); in To do it stays there.
     *
     * @return int how many of the actor's pings on the ticket the give cleared
     *
     * @throws TicketNotFound         when the key is malformed or the organization has no ticket with it
     * @throws TicketChangedMeanwhile when the ticket's version is not $expectedVersion, or another request saved it first
     * @throws MoveRefused            when the ticket has no team, the policy refuses give_it_to, the person is not the
     *                                team's lead or a member (ASSIGNEE_NOT_IN_TEAM_NOTE), or already has the ticket
     *                                (ALREADY_GIVEN_NOTE)
     */
    public function giveTo(OrganizationId $organizationId, UserId $actor, string $key, UserId $assignee, int $expectedVersion, \DateTimeImmutable $at): int
    {
        $ticket = $this->loadedTicket($organizationId, $key, $expectedVersion);
        $teamId = $ticket->team() ?? throw new MoveRefused(null);
        $team = $this->teamOf($teamId);
        $allowed = $this->allowedVerbMoveOf($ticket, $teamId, $team, $actor, self::GIVE_IT_TO);
        $target = $allowed->target;
        if (!$team->hasPossibleAssignee($assignee)) {
            throw new MoveRefused(self::ASSIGNEE_NOT_IN_TEAM_NOTE);
        }
        $fromStepId = $ticket->stepId();
        $previousAssignee = $ticket->assignee();
        if (null !== $previousAssignee && $previousAssignee->equals($assignee)) {
            throw new MoveRefused(self::ALREADY_GIVEN_NOTE, ['assignee' => $assignee]);
        }
        if ($target->id() !== $fromStepId) {
            $ticket->move($target->id(), $target->groupCode(), false, null, $at);
        }
        $ticket->giveTo($assignee, $at);

        $clearedPingCount = $this->saveAndRecord($ticket, $team, $allowed->before, $actor, $fromStepId, $target->id(), self::GIVE_IT_TO, $assignee, $at, $allowed->waitingTurns);
        $this->ticketEvents->publish(new TicketAssigned(
            $ticket->organizationId()->toString(),
            $allowed->before->ticketId->toString(),
            $ticket->key()->toString(),
            $previousAssignee?->toString(),
            $assignee->toString(),
            $actor->toString(),
            $at,
        ));

        return $clearedPingCount;
    }

    /**
     * Undo after a mistaken move (gap list 1.10): puts back what the ticket's last move changed (its step, who it waits
     * on, its reviewer and tester, closed_at), so whose turn it is is the one before the move, once Workflow's policy
     * allows it (TransitionPolicy::checkUndo: the mover, within UNDO_SECONDS of $at, while the move is the last change).
     * Whoever gets the turn back keeps the wait they had before the move, rather than starting a new one.
     * Recorded as a move with the verb UNDO, which no Undo takes back, and published as TicketMoved. A round the move
     * closed stays closed and a draft it shared stays shared.
     *
     * @param int $expectedVersion the version the move left the ticket at, so any save since refuses the Undo
     *
     * @return MoveResult the pings the Undo cleared
     *
     * @throws TicketNotFound         when the key is malformed or the organization has no ticket with it
     * @throws TicketChangedMeanwhile when the ticket's version is not $expectedVersion, or another request saved it first
     * @throws MoveRefused            when the ticket has no team or no history, or the policy refuses the Undo (with its
     *                                note); nothing is saved
     */
    public function undoMove(OrganizationId $organizationId, UserId $actor, string $key, int $expectedVersion, \DateTimeImmutable $at): MoveResult
    {
        $ticket = $this->loadedTicket($organizationId, $key, $expectedVersion);
        $teamId = $ticket->team() ?? throw new MoveRefused(null);
        $lastChange = $this->ticketHistory->lastChangeOf($ticket->id(), $ticket->createdAt()) ?? throw new MoveRefused(null);
        $movedFrom = $lastChange->movedFrom;
        $team = $this->teamOf($teamId);
        $before = $this->ticketTurnSnapshots->ofTeamTicket($ticket, $team->steps, $team->people);
        $waitingTurns = $this->waitingTurnsOf($before);
        // A last change that is no undoable move (a give, an Undo, a review change) is asked about as the actor's own
        // move changed since, so the refusal says the ticket changed rather than naming its writer as the mover.
        $move = null === $movedFrom
            ? new MoveToUndo($actor, $ticket->stepId(), $lastChange->at, false)
            : new MoveToUndo($lastChange->actorId, $lastChange->toStepId ?? '', $lastChange->at, true);
        $target = $team->stepOfId($movedFrom->stepId ?? $ticket->stepId());
        $decision = $this->transitionPolicy->checkUndo(
            TurnInputs::ticketStateOf($before),
            $team->actorInTeam($actor),
            self::teamFactsOf($before, $waitingTurns),
            $move,
            $target,
            $this->stepQueries->moveTargetsFor($teamId),
            $at,
        );
        self::refuseUnlessAllowed($decision);
        if (null === $movedFrom) {
            throw new \LogicException('The policy allows an Undo only of a move that is the last change.');
        }
        $fromStepId = $ticket->stepId();
        $ticket->undoMove($movedFrom, $at);

        $clearedPingCount = $this->saveAndRecord($ticket, $team, $before, $actor, $fromStepId, $target->id(), self::UNDO, null, $at, $waitingTurns);
        // The refresh started the wait of whoever has the turn back at $at; the person the move took it from keeps theirs.
        if (null !== $lastChange->turnWaitBefore) {
            $this->turnWaits->restore($ticket->id(), $lastChange->turnWaitBefore);
        }

        return new MoveResult($clearedPingCount);
    }

    /**
     * @throws MoveRefused when the policy refuses the verb, or its target is not a step of the team
     */
    private function allowedVerbMoveOf(Ticket $ticket, TeamId $teamId, MoveTeam $team, UserId $actor, string $verb): AllowedVerbMove
    {
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

        return new AllowedVerbMove(
            $before,
            $waitingTurns,
            $teamFacts,
            $team->targetStepOf($decision->moveTarget ?? throw new \LogicException('An allowed decision has a move target.')),
            $decision->isLeadOnlyMove,
        );
    }

    /**
     * @return MoveResult the pings the move cleared and what it did with the actor's draft round
     *
     * @throws TicketNotFound         when the key is malformed or the organization has no ticket with it
     * @throws TicketChangedMeanwhile when the ticket's version is not $expectedVersion, or another request saved it first,
     *                                or the actor's draft first; in that last case the move is written, so the caller
     *                                rolls back
     * @throws MoveRefused            when the ticket has no team, the step is not one of its team, is its step now,
     *                                the policy refuses it, a waiting-on is given for a step other than Needs input,
     *                                or the person to wait on (the reporter when nobody is named) is no member of the
     *                                organization; or as move does for a share
     * @throws WaitingOnRefused       when both a person and a label are given, or the label is not one line of at
     *                                most 100 characters
     * @throws DraftDecisionNeeded    when $draftChoice is null and the actor has an undecided draft on the ticket
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
        ?DraftChoice $draftChoice = DraftChoice::Keep,
    ): MoveResult {
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

        return $this->moveAndRecord($ticket, $team, $before, $actor, $target, $waitingOn, null, $at, $waitingTurns, self::teamFactsOf($before, $waitingTurns), $decision->isLeadOnlyMove, $draftChoice);
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
     * turn refresh gets, so the stored person, the turn row and who may approve are one person. Both moves change the
     * step, so both ask about the actor's undecided draft here, after every check of the move but the save, and decide
     * it after the save (ADR-0017, hand-off); a give keeps its step or only leaves Backlog, and does not ask. Between
     * the save and the draft's share, Review closes the shared rounds the move ends (ADR-0048: close first, then
     * number), from whose turn it was before the move.
     *
     * @param array<string, array<string, int>> $waitingTurns   the team's waiting reviews and tests already read for $before
     * @param TeamFacts                         $teamFacts      the team facts built from $before and $waitingTurns
     * @param bool                              $isLeadOnlyMove whether only the team's lead may make this move
     *
     * @return MoveResult the pings the move cleared and what it did with the actor's draft round
     *
     * @throws DraftDecisionNeeded when $draftChoice is null and the actor has an undecided draft on the ticket
     * @throws MoveRefused         when $draftChoice is Share and a shared round stays open after the move's close; the
     *                             move is written, so the caller rolls back
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
        bool $isLeadOnlyMove,
        ?DraftChoice $draftChoice,
    ): MoveResult {
        $fromStepId = $ticket->stepId();
        if ($target->id() === $fromStepId) {
            throw new MoveRefused(null);
        }
        $moveFacts = new MoveFacts(
            $actor,
            $before->assigneeId,
            $isLeadOnlyMove,
            // A lead-only move closes no round whoever's turn it is, so the turn is worked out only for the others.
            !$isLeadOnlyMove && $this->wasTurnOf($actor, $before, $teamFacts),
        );
        if (null === $draftChoice) {
            $draft = $this->shareDraftQuestion->undecidedDraftOf($ticket->id(), $moveFacts);
            if (null !== $draft) {
                throw new DraftDecisionNeeded($draft);
            }
            $draftId = null;
        } else {
            $draftId = $this->shareDraftQuestion->undecidedDraftIdOf($ticket->id(), $actor);
        }
        $movedFrom = $ticket->movedFrom();
        $turnWaitBefore = $this->turnWaits->waitOf($ticket->id());
        $ticket->move(
            $target->id(),
            $target->groupCode(),
            self::NEEDS_INPUT === $target->coreCode(),
            $waitingOn,
            $at,
            self::REVIEW === $target->coreCode() ? $this->turnPeopleChoice->reviewerToStore($before->assigneeId, $teamFacts) : null,
            self::TEST === $target->coreCode() ? $this->turnPeopleChoice->testerToStore($teamFacts) : null,
        );

        $clearedPingCount = $this->saveAndRecord($ticket, $team, $before, $actor, $fromStepId, $target->id(), $verb, null, $at, $waitingTurns, $movedFrom, $turnWaitBefore);
        $this->shareDraftQuestion->closeRoundsOnMove($ticket->id(), $moveFacts, $at);
        if (null === $draftId) {
            return new MoveResult($clearedPingCount);
        }
        if (DraftChoice::Share === $draftChoice) {
            return new MoveResult($clearedPingCount, DraftChoice::Share, $this->shareDraftQuestion->share($draftId, $actor, $at));
        }
        $this->shareDraftQuestion->keepPrivate($draftId, $actor, $at);

        return new MoveResult($clearedPingCount, DraftChoice::Keep);
    }

    /**
     * From Workflow's turn rules on the facts the policy decided the move on, so the turn and the verbs it allowed
     * agree, and no turn row is read.
     */
    private function wasTurnOf(UserId $actor, TicketTurnSnapshot $before, TeamFacts $teamFacts): bool
    {
        $turnUserId = $this->turnRules->turnFor(TurnInputs::ticketStateOf($before), $teamFacts)->userId;

        return null !== $turnUserId && $turnUserId->equals($actor);
    }

    /**
     * Saves the changed ticket under its version lock, refreshes its turn row, writes its "moved" history row and,
     * when it left its step, TicketMoved, and clears the actor's pings on the ticket (FR-PNG-05), all in the caller's
     * transaction.
     *
     * @param UserId|null                       $givenTo        the person a give_it_to names, recorded in the history row
     * @param array<string, array<string, int>> $waitingTurns   the team's waiting reviews and tests already read for $before
     * @param MovedFrom|null                    $movedFrom      what a move Undo may take back changed, as it was before;
     *                                                          null for a give and an Undo
     * @param TurnWait|null                     $turnWaitBefore whose turn it was before the move and since when, for
     *                                                          its Undo; null for a give, an Undo and a ticket with no turn row
     *
     * @return int how many of the actor's pings on the ticket it cleared
     *
     * @throws TicketChangedMeanwhile when another request saved the ticket first
     */
    private function saveAndRecord(
        Ticket $ticket,
        MoveTeam $team,
        TicketTurnSnapshot $before,
        UserId $actor,
        string $fromStepId,
        string $toStepId,
        ?string $verb,
        ?UserId $givenTo,
        \DateTimeImmutable $at,
        array $waitingTurns,
        ?MovedFrom $movedFrom = null,
        ?TurnWait $turnWaitBefore = null,
    ): int {
        try {
            $this->tickets->save($ticket);
        } catch (TicketVersionConflict $conflict) {
            throw new TicketChangedMeanwhile('The ticket was changed after it was read.', 0, $conflict);
        }
        $clearedPingCount = $this->pingClearing->clearForActor($ticket->organizationId(), $before->ticketId, $actor, $at);
        $overrideNote = $this->turnProjector->refreshFrom($this->ticketTurnSnapshots->ofTeamTicket($ticket, $team->steps, $team->people), $waitingTurns);
        if (null === $givenTo) {
            $this->ticketHistory->recordMoved($ticket->organizationId(), $ticket->id(), $actor, $fromStepId, $toStepId, $verb, $overrideNote, $at, $movedFrom, $turnWaitBefore);
        } else {
            $this->ticketHistory->recordGiven($ticket->organizationId(), $ticket->id(), $actor, $fromStepId, $toStepId, $givenTo, $at);
        }
        // Only a give inside To do stays in its step (a move to the same step is refused); its TicketAssigned is its one
        // event.
        if ($fromStepId === $toStepId) {
            return $clearedPingCount;
        }
        $this->ticketEvents->publish(new TicketMoved(
            $ticket->organizationId()->toString(),
            $before->ticketId->toString(),
            $ticket->key()->toString(),
            $fromStepId,
            $toStepId,
            $verb,
            $actor->toString(),
            $at,
        ));

        return $clearedPingCount;
    }

    private static function refuseUnlessAllowed(Decision $decision): void
    {
        if (!$decision->isAllowed) {
            throw new MoveRefused($decision->ruleNote?->template, $decision->ruleNote->people ?? []);
        }
    }
}

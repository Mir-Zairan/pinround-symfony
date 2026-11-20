<?php

declare(strict_types=1);

namespace App\Workflow\Application\Transition;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\Availability;
use App\Workflow\Api\Decision;
use App\Workflow\Api\MoveChoice;
use App\Workflow\Api\MoveChoiceGroup;
use App\Workflow\Api\MoveChoices;
use App\Workflow\Api\MoveTarget;
use App\Workflow\Api\RuleNote;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\TransitionPolicy;
use App\Workflow\Api\TransitionView;
use App\Workflow\Application\Turn\ReviewerChoice;
use App\Workflow\Application\Turn\TesterChoice;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Group;
use App\Workflow\Domain\Mover;
use App\Workflow\Domain\MoveSideEffects;
use App\Workflow\Domain\Transition;
use App\Workflow\Domain\TransitionCatalogue;
use App\Workflow\Domain\Verb;

/**
 * The transition policy over the verb catalogue and the guards. In this order: a person without the team role lead or
 * member gets no verb and the managers' note; an open question pauses every move; then every verb that applies in the
 * ticket's step is offered to its movers unless a guard blocks it. The first blocking note in catalogue order is the
 * rule note. At most one button is filled: the first offered verb the catalogue marks primary (ticket-page.md, "Bold =
 * filled"); in Backlog the lead sees Plan it filled and Give it to… outline. In a company or team step only Put on hold
 * and Move on to… apply, and no guard blocks Move on to…: review_before_test is about In progress → Testing and
 * no_self_review about Code review → Testing, neither a step Move on to… leaves. Every offered verb carries where it
 * goes (MoveSideEffects); a "Move on to…" label names the core step it goes to. Give it to… on a To do ticket that
 * already has an assignee is the lead's "Give it to someone else…" (workflow-engine.md); the checker gives only an
 * unassigned ticket.
 *
 * The reviewer and the tester are the ones ReviewerChoice::reviewerOf and TesterChoice::testerOf give, as in the turn
 * rules, so a ticket that reached Code review or Testing without one stored is still moved by the person whose turn
 * it is.
 *
 * "Move to another step…" is the lead's separate list (moveChoices) and check (checkMoveTo); both take the team's move
 * targets (StepQueries::moveTargetsFor), and checkMoveTo refuses a step outside them. The list shows the groups in
 * their fixed order (Group::cases(), To do to Done), not the list order moveTargetsFor sorts by.
 */
final readonly class CatalogueTransitionPolicy implements TransitionPolicy
{
    private const string NO_TEAM_ROLE_NOTE = 'Managers can read and comment. Only the team moves tickets.';
    private const string GIVE_IT_TO_SOMEONE_ELSE_LABEL = 'Give it to someone else…';

    /** @var list<Guard> */
    private array $guards;

    public function __construct(
        private TransitionCatalogue $catalogue,
        private QuestionOpenPause $questionOpenPause,
        private MoveRights $moveRights,
        private MoveSideEffects $moveSideEffects,
        TesterMovesDone $testerMovesDone,
        NoSelfReview $noSelfReview,
        private ReviewBeforeTest $reviewBeforeTest,
        private ReviewerChoice $reviewerChoice,
        private TesterChoice $testerChoice,
    ) {
        $this->guards = [$testerMovesDone, $noSelfReview, $reviewBeforeTest];
    }

    public function available(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, TeamFacts $team): Availability
    {
        $noteForEveryMove = $this->noteForEveryMove($ticket, $actor);
        if (null !== $noteForEveryMove) {
            return new Availability([], $noteForEveryMove);
        }

        $transitions = [];
        $ruleNote = null;
        $hasPrimary = false;
        foreach ($this->catalogue->all() as $transition) {
            if (!self::appliesToTicketsStep($transition, $ticket)) {
                continue;
            }

            $blockingNote = $this->blockingNote($ticket, $transition, $actor, $rules, $team);
            if ($transition->explainsBlock) {
                $ruleNote ??= $blockingNote;
            }

            if (null === $blockingNote && $this->mayUse($ticket, $transition, $actor, $team)) {
                $isPrimary = $transition->isPrimary && !$hasPrimary;
                $hasPrimary = $hasPrimary || $isPrimary;
                $transitions[] = $this->viewOf($transition, $ticket, $isPrimary);
            }
        }

        return new Availability($transitions, $ruleNote);
    }

    public function check(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, TeamFacts $team, string $verb): Decision
    {
        $noteForEveryMove = $this->noteForEveryMove($ticket, $actor);
        if (null !== $noteForEveryMove) {
            return Decision::refused($noteForEveryMove);
        }

        $knownVerb = Verb::tryFrom($verb);
        if (null === $knownVerb) {
            return Decision::refused(null);
        }

        $transition = $this->catalogue->for($knownVerb);
        if (!self::appliesToTicketsStep($transition, $ticket)) {
            return Decision::refused(null);
        }

        $blockingNote = $this->blockingNote($ticket, $transition, $actor, $rules, $team);
        if (null !== $blockingNote) {
            return Decision::refused($blockingNote);
        }

        return $this->mayUse($ticket, $transition, $actor, $team)
            ? Decision::allowed(self::moveTargetOf($this->coreTargetOf($transition, $ticket), $ticket), $transition->isLeadOnly())
            : Decision::refused(null);
    }

    /**
     * @param list<StepView> $steps
     */
    public function moveChoices(TicketState $ticket, ActorInTeam $actor, array $steps): MoveChoices
    {
        $noteForEveryMove = $this->noteForEveryMove($ticket, $actor);
        if (null !== $noteForEveryMove) {
            return new MoveChoices([], $noteForEveryMove);
        }

        if (!$this->moveRights->leadsTheTeam($actor)) {
            return new MoveChoices([], null);
        }

        $stepsByGroup = [];
        foreach ($steps as $step) {
            $group = Group::tryFrom($step->groupCode()) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a group.', $step->groupCode()));
            $stepsByGroup[$group->value][] = $step;
        }

        $groups = [];
        foreach (Group::cases() as $group) {
            $groupSteps = $stepsByGroup[$group->value] ?? [];
            if ([] === $groupSteps) {
                continue;
            }

            usort($groupSteps, static fn (StepView $left, StepView $right): int => $left->position() <=> $right->position());
            $groups[] = new MoveChoiceGroup(
                $group->value,
                $group->label(),
                array_map(static fn (StepView $step): MoveChoice => new MoveChoice($step, $step->id() === $ticket->stepId), $groupSteps),
            );
        }

        return new MoveChoices($groups, null);
    }

    /**
     * @param list<StepView> $steps
     */
    public function checkMoveTo(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, StepView $target, array $steps): Decision
    {
        $noteForEveryMove = $this->noteForEveryMove($ticket, $actor);
        if (null !== $noteForEveryMove) {
            return Decision::refused($noteForEveryMove);
        }

        if (!$this->moveRights->leadsTheTeam($actor) || $target->id() === $ticket->stepId) {
            return Decision::refused(null);
        }

        if (!array_any($steps, static fn (StepView $step): bool => $step->id() === $target->id())) {
            return Decision::refused(null);
        }

        if (CoreStep::Progress->value === $ticket->coreCode && CoreStep::Test->value === $target->coreCode()) {
            $note = $this->reviewBeforeTest->noteFor($rules);
            if (null !== $note) {
                return Decision::refused($note);
            }
        }

        return Decision::allowed(MoveTarget::step($target->id()), true);
    }

    private function noteForEveryMove(TicketState $ticket, ActorInTeam $actor): ?RuleNote
    {
        if (null === $ticket->teamId || !$this->moveRights->movesTickets($actor)) {
            return new RuleNote(self::NO_TEAM_ROLE_NOTE);
        }

        return $this->questionOpenPause->noteFor($ticket);
    }

    private static function appliesToTicketsStep(Transition $transition, TicketState $ticket): bool
    {
        if (null === $ticket->coreCode) {
            return $transition->appliesInCustomStep(self::groupOf($ticket));
        }

        $step = CoreStep::tryFrom($ticket->coreCode) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a core step.', $ticket->coreCode));

        return $transition->appliesIn($step, null !== $ticket->assigneeId);
    }

    /**
     * The ticket's group while it is in a company or team step; null in a core step, where the step alone decides.
     */
    private static function groupOfCustomStep(TicketState $ticket): ?Group
    {
        return null === $ticket->coreCode ? self::groupOf($ticket) : null;
    }

    private static function groupOf(TicketState $ticket): Group
    {
        return Group::tryFrom($ticket->groupCode) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a group.', $ticket->groupCode));
    }

    private function viewOf(Transition $transition, TicketState $ticket, bool $isPrimary): TransitionView
    {
        $coreTarget = $this->coreTargetOf($transition, $ticket);
        $label = match (true) {
            Verb::MoveOn === $transition->verb => 'Move on to '.($coreTarget?->stepName() ?? 'the previous step'),
            Verb::GiveItTo === $transition->verb && null !== $ticket->assigneeId => self::GIVE_IT_TO_SOMEONE_ELSE_LABEL,
            default => $transition->label,
        };

        return new TransitionView($transition->verb->value, $label, $isPrimary, self::moveTargetOf($coreTarget, $ticket));
    }

    private function coreTargetOf(Transition $transition, TicketState $ticket): ?CoreStep
    {
        return $this->moveSideEffects->coreTargetOf($transition, self::groupOfCustomStep($ticket), null !== $ticket->previousStepId);
    }

    private static function moveTargetOf(?CoreStep $coreTarget, TicketState $ticket): MoveTarget
    {
        return null === $coreTarget
            ? MoveTarget::step($ticket->previousStepId ?? throw new \LogicException('Only a ticket with a previous step goes back to it.'))
            : MoveTarget::coreStep($coreTarget->value);
    }

    private function blockingNote(TicketState $ticket, Transition $transition, ActorInTeam $actor, RuleSetView $rules, TeamFacts $team): ?RuleNote
    {
        foreach ($this->guards as $guard) {
            $note = $guard->check($ticket, $transition, $actor, $rules, $team);
            if (null !== $note) {
                return $note;
            }
        }

        return null;
    }

    private function mayUse(TicketState $ticket, Transition $transition, ActorInTeam $actor, TeamFacts $team): bool
    {
        return array_any($transition->movers, fn (Mover $mover): bool => $this->isMover($mover, $ticket, $actor, $team));
    }

    /**
     * A tester is the ticket's tester (with none stored, the one TesterChoice finds: the lead in a team without a
     * tester) or anyone with the job tester in the team (personas-and-roles.md: Pass it and Needs changes are for the
     * job tester); a checker only counts while the check is needed.
     */
    private function isMover(Mover $mover, TicketState $ticket, ActorInTeam $actor, TeamFacts $team): bool
    {
        return match ($mover) {
            Mover::Lead => $this->moveRights->leadsTheTeam($actor),
            Mover::LeadWhenUnassigned => $this->moveRights->leadsTheTeam($actor) && null === $ticket->assigneeId,
            Mover::Assignee => self::isSamePerson($ticket->assigneeId, $actor->userId),
            Mover::WaitedOn => self::isSamePerson(self::personWaitedOn($ticket), $actor->userId),
            Mover::CheckerWhenUnassigned => $ticket->isCheckNeeded && null === $ticket->assigneeId && self::isSamePerson($ticket->checkerId, $actor->userId),
            Mover::Reviewer => self::isSamePerson($this->reviewerChoice->reviewerOf($ticket, $team)->userId, $actor->userId),
            Mover::Tester => $this->isTesterOf($ticket, $actor, $team),
            Mover::TesterInCheckingStep => null === $ticket->coreCode && Group::Check === self::groupOf($ticket) && $this->isTesterOf($ticket, $actor, $team),
        };
    }

    private function isTesterOf(TicketState $ticket, ActorInTeam $actor, TeamFacts $team): bool
    {
        return $this->moveRights->isTester($actor) || self::isSamePerson($this->testerChoice->testerOf($ticket, $team)->userId, $actor->userId);
    }

    /**
     * Whose turn it is in Needs input: the user the ticket waits on, else the reporter while no label names someone
     * outside the system (workflow-engine.md, "Whose turn").
     */
    private static function personWaitedOn(TicketState $ticket): ?UserId
    {
        return $ticket->waitingOnUserId ?? (null === $ticket->waitingOnLabel ? $ticket->reporterId : null);
    }

    private static function isSamePerson(?UserId $ticketPerson, UserId $actorId): bool
    {
        return null !== $ticketPerson && $ticketPerson->equals($actorId);
    }
}

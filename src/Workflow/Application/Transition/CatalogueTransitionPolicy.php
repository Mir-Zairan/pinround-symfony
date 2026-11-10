<?php

declare(strict_types=1);

namespace App\Workflow\Application\Transition;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\Availability;
use App\Workflow\Api\Decision;
use App\Workflow\Api\RuleNote;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\TransitionPolicy;
use App\Workflow\Api\TransitionView;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Mover;
use App\Workflow\Domain\Transition;
use App\Workflow\Domain\TransitionCatalogue;
use App\Workflow\Domain\Verb;

/**
 * The transition policy over the verb catalogue and the guards. In this order: a person without the team role lead or
 * member gets no verb and the managers' note; an open question pauses every move; then every verb that applies in the
 * ticket's step is offered to its movers unless a guard blocks it. The first blocking note in catalogue order is the
 * rule note. A company or team step has no verb yet (Move on to… comes with P02.T5).
 */
final readonly class CatalogueTransitionPolicy implements TransitionPolicy
{
    private const string NO_TEAM_ROLE_NOTE = 'Managers can read and comment. Only the team moves tickets.';

    /** @var list<Guard> */
    private array $guards;

    public function __construct(
        private TransitionCatalogue $catalogue,
        private QuestionOpenPause $questionOpenPause,
        private MoveRights $moveRights,
        TesterMovesDone $testerMovesDone,
        NoSelfReview $noSelfReview,
        ReviewBeforeTest $reviewBeforeTest,
    ) {
        $this->guards = [$testerMovesDone, $noSelfReview, $reviewBeforeTest];
    }

    public function available(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules): Availability
    {
        $noteForEveryMove = $this->noteForEveryMove($ticket, $actor);
        if (null !== $noteForEveryMove) {
            return new Availability([], $noteForEveryMove);
        }

        $transitions = [];
        $ruleNote = null;
        foreach ($this->catalogue->all() as $transition) {
            if (!self::appliesToTicketsStep($transition, $ticket)) {
                continue;
            }

            $blockingNote = $this->blockingNote($ticket, $transition, $actor, $rules);
            $ruleNote ??= $blockingNote;
            if (null === $blockingNote && $this->mayUse($ticket, $transition, $actor)) {
                $transitions[] = new TransitionView($transition->verb->value, $transition->label, $transition->isPrimary);
            }
        }

        return new Availability($transitions, $ruleNote);
    }

    public function check(TicketState $ticket, ActorInTeam $actor, RuleSetView $rules, string $verb): Decision
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

        $blockingNote = $this->blockingNote($ticket, $transition, $actor, $rules);
        if (null !== $blockingNote) {
            return Decision::refused($blockingNote);
        }

        return $this->mayUse($ticket, $transition, $actor) ? Decision::allowed() : Decision::refused(null);
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
            return false;
        }

        $step = CoreStep::tryFrom($ticket->coreCode) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a core step.', $ticket->coreCode));

        return $transition->appliesIn($step, null !== $ticket->assigneeId);
    }

    private function blockingNote(TicketState $ticket, Transition $transition, ActorInTeam $actor, RuleSetView $rules): ?RuleNote
    {
        foreach ($this->guards as $guard) {
            $note = $guard->check($ticket, $transition, $actor, $rules);
            if (null !== $note) {
                return $note;
            }
        }

        return null;
    }

    private function mayUse(TicketState $ticket, Transition $transition, ActorInTeam $actor): bool
    {
        return array_any($transition->movers, fn (Mover $mover): bool => $this->isMover($mover, $ticket, $actor));
    }

    /**
     * A tester is the ticket's tester or anyone with the job tester in the team (personas-and-roles.md: Pass it and
     * Needs changes are for the job tester); a checker only counts while the check is needed.
     */
    private function isMover(Mover $mover, TicketState $ticket, ActorInTeam $actor): bool
    {
        return match ($mover) {
            Mover::Lead => $this->moveRights->leadsTheTeam($actor),
            Mover::LeadWhenUnassigned => $this->moveRights->leadsTheTeam($actor) && null === $ticket->assigneeId,
            Mover::Assignee => self::isSamePerson($ticket->assigneeId, $actor->userId),
            Mover::Checker => $ticket->isCheckNeeded && self::isSamePerson($ticket->checkerId, $actor->userId),
            Mover::Reviewer => self::isSamePerson($ticket->reviewerId, $actor->userId),
            Mover::Tester => $this->moveRights->isTester($actor) || self::isSamePerson($ticket->testerId, $actor->userId),
        };
    }

    private static function isSamePerson(?UserId $ticketPerson, UserId $actorId): bool
    {
        return null !== $ticketPerson && $ticketPerson->equals($actorId);
    }
}

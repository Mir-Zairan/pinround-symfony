<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\ActorContext;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\MoveChoices;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNext;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Api\TransitionPolicy;
use App\Workflow\Api\VerbFor;

/**
 * The "Your next step" box of the ticket page (ticket-page.md, Your next step box): its sentence, the verbs
 * TransitionPolicy::available gives the viewer, the rule note, and the lead's "Move to another step…" list. Reads no
 * database but the team's move targets for a lead (StepQueries::moveTargetsFor, cached).
 */
final readonly class NextStepChoices
{
    public const string DONE = 'Nothing. This ticket is done.';
    public const string CHECK = 'Take a quick look at this new ticket.';
    public const string NOTHING_FOR_YOU = 'Nothing for you. %s.';
    public const string NOTHING_FOR_YOU_NOW = 'Nothing for you now. %s’s turn.';

    public function __construct(
        private TransitionPolicy $transitionPolicy,
        private StepQueries $stepQueries,
        private VerbFor $verbFor,
        private NowNext $nowNext,
    ) {
    }

    /**
     * @param bool $mayMove whether the viewer may move the ticket (TicketPermission::MOVE); when false they get no
     *                      team role here, so no verb and no move list, whatever their roles say
     *
     * @throws \InvalidArgumentException when the ticket's core code is not one of the core steps
     */
    public function of(PageTurn $pageTurn, ActorContext $viewer, bool $mayMove): NextStep
    {
        $actor = $this->actorInTeamOf($pageTurn, $viewer, $mayMove);
        $availability = $this->transitionPolicy->available($pageTurn->ticket, $actor, $pageTurn->rules, $pageTurn->team);
        $moveChoices = ActorInTeam::TEAM_ROLE_LEAD === $actor->teamRole
            ? $this->transitionPolicy->moveChoices($pageTurn->ticket, $actor, $this->stepQueries->moveTargetsFor($pageTurn->teamId))
            : new MoveChoices([], null);

        // The move list's note is only ever the one available() gives too (MoveChoices), so the box shows that one.
        return new NextStep(
            $this->sentenceOf($pageTurn, $actor),
            $availability->transitions,
            $availability->ruleNote?->render(static fn (UserId $userId): string => $pageTurn->context->firstNameOf($userId) ?? TicketShowController::UNKNOWN_PERSON),
            $moveChoices->groups,
        );
    }

    private function actorInTeamOf(PageTurn $pageTurn, ActorContext $viewer, bool $mayMove): ActorInTeam
    {
        $roles = $viewer->roles();
        $teamRole = match (true) {
            !$mayMove => null,
            $roles->isLeadOf($pageTurn->teamId) => ActorInTeam::TEAM_ROLE_LEAD,
            $roles->isMemberOf($pageTurn->teamId) => ActorInTeam::TEAM_ROLE_MEMBER,
            default => null,
        };
        if (null === $teamRole) {
            return new ActorInTeam($viewer->userId());
        }

        return new ActorInTeam($viewer->userId(), $teamRole, self::jobOf($pageTurn, $viewer->userId()));
    }

    /**
     * The job from the team's developers and testers, which the page already holds, so TeamQueries::jobOf adds no query.
     */
    private static function jobOf(PageTurn $pageTurn, UserId $userId): string
    {
        $isUser = static fn (TeamMemberLoad $member): bool => $member->userId->equals($userId);

        return match (true) {
            array_any($pageTurn->team->developers, $isUser) => ActorInTeam::JOB_DEVELOPER,
            array_any($pageTurn->team->testers, $isUser) => ActorInTeam::JOB_TESTER,
            default => ActorInTeam::JOB_OTHER,
        };
    }

    private function sentenceOf(PageTurn $pageTurn, ActorInTeam $actor): string
    {
        $turn = $pageTurn->turn;
        if (null === $turn->kind) {
            return self::DONE;
        }
        if (null !== $turn->userId && $turn->userId->equals($actor->userId)) {
            return NeedsYouKind::Check === $turn->kind
                ? self::CHECK
                : $this->verbFor->person($pageTurn->ticket, $turn, $pageTurn->context).'.';
        }
        if (null === $actor->teamRole) {
            return \sprintf(self::NOTHING_FOR_YOU, $this->nowNext->now($pageTurn->ticket, $turn, $pageTurn->context));
        }
        $name = (null === $turn->userId ? null : $pageTurn->context->firstNameOf($turn->userId)) ?? $turn->label ?? ucfirst(TicketShowController::UNKNOWN_PERSON);

        return \sprintf(self::NOTHING_FOR_YOU_NOW, $name);
    }
}

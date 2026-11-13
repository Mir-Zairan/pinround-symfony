<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MembershipQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\MoveChoice;
use App\Workflow\Api\MoveChoiceGroup;
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
 * database but, for a lead, the team's move targets (StepQueries::moveTargetsFor, cached) and, when the list offers
 * Needs input, which of the ticket's people are still members (one MembershipQueries::membersAmong).
 */
final readonly class NextStepChoices
{
    public const string DONE = 'Nothing. This ticket is done.';
    public const string CHECK = 'Take a quick look at this new ticket.';
    public const string NOTHING_FOR_YOU = 'Nothing for you. %s.';
    public const string NOTHING_FOR_YOU_NOW = 'Nothing for you now. %s’s turn.';

    /** The core code of Needs input; MoveTicket keeps its own, as Ticketing's Application may not import its UI. */
    private const string NEEDS_INPUT = 'input';

    /** The role words of the ticket page's People section (ticket-page.md, People). */
    private const string ROLE_REPORTER = 'Reporter';
    private const string ROLE_ASSIGNEE = 'Assignee';
    private const string ROLE_REVIEWER = 'Code reviewer';
    private const string ROLE_TESTER = 'Tester';

    public function __construct(
        private TransitionPolicy $transitionPolicy,
        private StepQueries $stepQueries,
        private VerbFor $verbFor,
        private NowNext $nowNext,
        private MembershipQueries $membershipQueries,
    ) {
    }

    /**
     * Whether the chip moves the ticket to Needs input, so it asks who the ticket waits on; the step the ticket is in is
     * no move, so its chip asks nothing.
     */
    public static function isMoveToNeedsInput(MoveChoice $choice): bool
    {
        return !$choice->isCurrent && self::NEEDS_INPUT === $choice->step->coreCode();
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
            self::offersMoveToNeedsInput($moveChoices->groups) ? $this->peopleToWaitOnOf($pageTurn, $viewer->organizationId()) : [],
        );
    }

    /**
     * @param list<MoveChoiceGroup> $groups
     */
    private static function offersMoveToNeedsInput(array $groups): bool
    {
        return array_any(
            $groups,
            static fn (MoveChoiceGroup $group): bool => array_any($group->choices, self::isMoveToNeedsInput(...)),
        );
    }

    /**
     * The ticket's people by the names the page already holds; anyone without a name is left out, as the dialog could
     * not name them, and so is anyone who left the organization, as MoveTicket refuses to wait on them.
     *
     * @return list<PersonToWaitOn>
     */
    private function peopleToWaitOnOf(PageTurn $pageTurn, OrganizationId $organizationId): array
    {
        $ticket = $pageTurn->ticket;
        $roleNamesByUser = [];
        foreach ([
            self::ROLE_REPORTER => $ticket->reporterId,
            self::ROLE_ASSIGNEE => $ticket->assigneeId,
            self::ROLE_REVIEWER => $ticket->reviewerId,
            self::ROLE_TESTER => $ticket->testerId,
        ] as $roleName => $userId) {
            if (null !== $userId) {
                $roleNamesByUser[$userId->toString()][] = $roleName;
            }
        }
        $firstNamesByUser = [];
        foreach (array_keys($roleNamesByUser) as $userKey) {
            $firstName = $pageTurn->context->firstNameOf(new UserId($userKey));
            if (null !== $firstName) {
                $firstNamesByUser[$userKey] = $firstName;
            }
        }
        $members = $this->membershipQueries->membersAmong(
            $organizationId,
            array_map(static fn (string $userKey): UserId => new UserId($userKey), array_keys($firstNamesByUser)),
        );
        $memberKeys = array_map(static fn (UserId $memberId): string => $memberId->toString(), $members);

        $people = [];
        foreach ($firstNamesByUser as $userKey => $firstName) {
            if (\in_array($userKey, $memberKeys, true)) {
                $people[] = new PersonToWaitOn($userKey, $firstName, implode(' · ', $roleNamesByUser[$userKey]));
            }
        }

        return $people;
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

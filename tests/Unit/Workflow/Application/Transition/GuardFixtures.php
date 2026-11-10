<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Api\TicketState;
use App\Workflow\Application\Transition\CatalogueTransitionPolicy;
use App\Workflow\Application\Transition\MoveRights;
use App\Workflow\Application\Transition\NoSelfReview;
use App\Workflow\Application\Transition\QuestionOpenPause;
use App\Workflow\Application\Transition\ReviewBeforeTest;
use App\Workflow\Application\Transition\TesterMovesDone;
use App\Workflow\Application\Turn\ReviewerChoice;
use App\Workflow\Application\Turn\TesterChoice;
use App\Workflow\Domain\MoveSideEffects;
use App\Workflow\Domain\TransitionCatalogue;
use Random\Randomizer;

/**
 * The Shop team of Acme Foods: Lena leads it, Omar develops, Mia tests, and a viewer reads along. Raj is a manager and
 * Sara an admin, both without a role in the team; another person is a member of another team only.
 */
final class GuardFixtures
{
    public const string LENA = '0190a000-0000-7000-8000-00000000000a';
    public const string OMAR = '0190a000-0000-7000-8000-00000000000b';
    public const string MIA = '0190a000-0000-7000-8000-00000000000c';
    public const string RAJ = '0190a000-0000-7000-8000-00000000000d';
    public const string SARA = '0190a000-0000-7000-8000-00000000000e';
    public const string VIEWER = '0190a000-0000-7000-8000-00000000001a';
    public const string OTHER_TEAM_MEMBER = '0190a000-0000-7000-8000-00000000001b';

    private const string TEAM = '0190a000-0000-7000-8000-0000000000f2';
    private const string STEP = '0190a000-0000-7000-8000-0000000000f3';
    private const string PREVIOUS_STEP = '0190a000-0000-7000-8000-0000000000f4';

    private const array NAMES = [self::LENA => 'Lena', self::OMAR => 'Omar', self::MIA => 'Mia', self::RAJ => 'Raj', self::SARA => 'Sara'];

    public static function lena(string $job = 'developer'): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::LENA), 'lead', $job);
    }

    public static function omar(string $job = 'developer'): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::OMAR), 'member', $job);
    }

    public static function mia(): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::MIA), 'member', 'tester');
    }

    public static function raj(): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::RAJ));
    }

    public static function sara(): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::SARA));
    }

    /**
     * With the job tester, so the job alone does not make anyone a mover.
     */
    public static function viewer(): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::VIEWER), 'viewer', 'tester');
    }

    public static function otherTeamMember(): ActorInTeam
    {
        return new ActorInTeam(new UserId(self::OTHER_TEAM_MEMBER));
    }

    public static function nameOf(UserId $userId): string
    {
        return self::NAMES[$userId->toString()] ?? throw new \LogicException('No name for '.$userId->toString());
    }

    public static function rules(bool $testerMovesDone = true, bool $noSelfReview = true, bool $reviewBeforeTest = true): RuleSetView
    {
        return new RuleSetView($testerMovesDone, $noSelfReview, $reviewBeforeTest);
    }

    public static function ticket(
        ?string $coreCode,
        string $groupCode,
        ?string $assignee = null,
        ?string $reviewer = null,
        ?string $tester = null,
        ?string $asked = null,
        ?string $checker = null,
        bool $hasTeam = true,
        ?string $previousStepId = null,
        ?string $reporter = null,
        ?string $waitingOnUser = null,
        ?string $waitingOnLabel = null,
    ): TicketState {
        return new TicketState(
            stepId: self::STEP,
            coreCode: $coreCode,
            groupCode: $groupCode,
            teamId: $hasTeam ? new TeamId(self::TEAM) : null,
            assigneeId: self::userIdOrNull($assignee),
            reviewerId: self::userIdOrNull($reviewer),
            testerId: self::userIdOrNull($tester),
            askedUserId: self::userIdOrNull($asked),
            previousStepId: $previousStepId,
            reporterId: self::userIdOrNull($reporter),
            waitingOnUserId: self::userIdOrNull($waitingOnUser),
            waitingOnLabel: $waitingOnLabel,
            isCheckNeeded: null !== $checker,
            checkerId: self::userIdOrNull($checker),
        );
    }

    /**
     * The uuid of a step the ticket was in before; any valid id will do, the policy only passes it on.
     */
    public static function previousStepId(): string
    {
        return self::PREVIOUS_STEP;
    }

    public static function ticketStepId(): string
    {
        return self::STEP;
    }

    /**
     * Wait, Check and Doing before To do and Done, as StepQueries::moveTargetsFor orders them; index 5 is In progress,
     * the step of ticketStepId().
     *
     * @return list<StepView>
     */
    public static function steps(): array
    {
        return [
            new StepView('0190a000-0000-7000-8000-000000000101', 'Needs input', 'wait', 'Waiting', 5, 'core', 'input'),
            new StepView('0190a000-0000-7000-8000-000000000102', 'On hold', 'wait', 'Waiting', 6, 'core', 'hold'),
            new StepView('0190a000-0000-7000-8000-000000000103', 'Code review', 'check', 'Checking', 3, 'core', 'review'),
            new StepView('0190a000-0000-7000-8000-000000000104', 'Testing', 'check', 'Checking', 4, 'core', 'test'),
            new StepView('0190a000-0000-7000-8000-000000000105', 'Design check', 'check', 'Checking', 10, 'team', null),
            new StepView(self::STEP, 'In progress', 'doing', 'Doing', 2, 'core', 'progress'),
            new StepView('0190a000-0000-7000-8000-000000000107', 'Backlog', 'todo', 'To do', 0, 'core', 'backlog'),
            new StepView('0190a000-0000-7000-8000-000000000108', 'To do', 'todo', 'To do', 1, 'core', 'todo'),
            new StepView('0190a000-0000-7000-8000-000000000109', 'Done', 'done', 'Done', 7, 'core', 'done'),
        ];
    }

    /**
     * steps() and a team step in To do, Doing and Waiting, so a random ticket also lands in each group's custom step.
     *
     * @return list<StepView>
     */
    public static function stepsWithTeamSteps(): array
    {
        return [
            ...self::steps(),
            new StepView('0190a000-0000-7000-8000-000000000110', 'Ready to build', 'todo', 'To do', 11, 'team', null),
            new StepView('0190a000-0000-7000-8000-000000000111', 'Pairing', 'doing', 'Doing', 12, 'team', null),
            new StepView('0190a000-0000-7000-8000-000000000112', 'Waiting on supplier', 'wait', 'Waiting', 13, 'team', null),
        ];
    }

    /**
     * The Shop team as the turn rules see it: Lena leads and develops, Omar develops, Mia tests. Without $testers the
     * team has no tester; with $hasOtherDeveloper false Lena is its only developer.
     */
    public static function team(bool $testers = true, bool $hasOtherDeveloper = true): TeamFacts
    {
        $developers = [new TeamMemberLoad(new UserId(self::LENA))];
        if ($hasOtherDeveloper) {
            $developers[] = new TeamMemberLoad(new UserId(self::OMAR));
        }

        return new TeamFacts(new UserId(self::LENA), 'Lena', $developers, $testers ? [new TeamMemberLoad(new UserId(self::MIA))] : []);
    }

    public static function policy(): CatalogueTransitionPolicy
    {
        $moveRights = new MoveRights();

        return new CatalogueTransitionPolicy(new TransitionCatalogue(), new QuestionOpenPause(), $moveRights, new MoveSideEffects(), new TesterMovesDone($moveRights), new NoSelfReview(new ReviewerChoice()), new ReviewBeforeTest(), new ReviewerChoice(), new TesterChoice());
    }

    /**
     * A ticket in one of $steps with a random team (or none), people (any of userIds() or nobody), question, previous
     * step, waiting on and check.
     *
     * @param list<StepView> $steps the step the ticket is in is one of them, and gives its core and group codes
     */
    public static function randomTicket(Randomizer $random, array $steps): TicketState
    {
        $step = $steps[$random->getInt(0, \count($steps) - 1)];
        $user = static fn (): ?UserId => self::userIdOrNull(self::userIds()[$random->getInt(0, \count(self::userIds()) - 1)]);
        $isCheckNeeded = 1 === $random->getInt(0, 1);

        return new TicketState(
            stepId: $step->id(),
            coreCode: $step->coreCode(),
            groupCode: $step->groupCode(),
            teamId: 1 === $random->getInt(0, 4) ? null : new TeamId(self::TEAM),
            assigneeId: $user(),
            reviewerId: $user(),
            testerId: $user(),
            askedUserId: 1 === $random->getInt(0, 5) ? $user() : null,
            previousStepId: 1 === $random->getInt(0, 1) ? self::PREVIOUS_STEP : null,
            reporterId: $user(),
            waitingOnUserId: $user(),
            waitingOnLabel: 1 === $random->getInt(0, 1) ? 'Supplier' : null,
            isCheckNeeded: $isCheckNeeded,
            checkerId: $user(),
        );
    }

    /**
     * @return list<string|null> the people of this team and outside it, and nobody
     */
    public static function userIds(): array
    {
        return [null, self::LENA, self::OMAR, self::MIA, self::RAJ, self::SARA];
    }

    private static function userIdOrNull(?string $userId): ?UserId
    {
        return null === $userId ? null : new UserId($userId);
    }
}

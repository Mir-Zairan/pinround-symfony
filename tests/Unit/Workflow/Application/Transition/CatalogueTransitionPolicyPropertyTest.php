<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\StepView;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\TransitionView;
use App\Workflow\Application\Transition\CatalogueTransitionPolicy;
use App\Workflow\Application\Transition\MoveRights;
use App\Workflow\Application\Transition\NoSelfReview;
use App\Workflow\Application\Transition\QuestionOpenPause;
use App\Workflow\Application\Transition\ReviewBeforeTest;
use App\Workflow\Application\Transition\TesterMovesDone;
use App\Workflow\Domain\MoveSideEffects;
use App\Workflow\Domain\TransitionCatalogue;
use App\Workflow\Domain\Verb;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * 1,000 seeded random tickets, people and rules: what available() and moveChoices() list is exactly what check() and
 * checkMoveTo() allow.
 */
final class CatalogueTransitionPolicyPropertyTest extends TestCase
{
    private const int COMBINATIONS = 1000;
    private const string REVIEW_FIRST_NOTE = 'Team rule: code review comes before testing.';

    private const array GROUP_OF_CORE_STEP = [
        'backlog' => 'todo',
        'todo' => 'todo',
        'progress' => 'doing',
        'review' => 'check',
        'test' => 'check',
        'input' => 'wait',
        'hold' => 'wait',
        'done' => 'done',
    ];

    private const array GROUPS = ['todo', 'doing', 'check', 'wait', 'done'];

    #[Test]
    public function whatIsOfferedIsAllowedAndWhatIsNotOfferedIsRefusedInEveryRandomCombination(): void
    {
        $random = new Randomizer(new Mt19937(1));
        $policy = self::policy();
        $people = self::people();
        $steps = self::steps();
        $combinationsWithAVerb = 0;
        $combinationsWithAMoveChoice = 0;

        for ($i = 0; $i < self::COMBINATIONS; ++$i) {
            $ticket = self::randomTicket($random);
            $actor = $people[$random->getInt(0, \count($people) - 1)];
            $rules = new RuleSetView(1 === $random->getInt(0, 1), 1 === $random->getInt(0, 1), 1 === $random->getInt(0, 1));
            $context = \sprintf('combination %d', $i);

            $offered = [];
            foreach ($policy->available($ticket, $actor, $rules)->transitions as $view) {
                $offered[$view->verb] = $view;
            }

            foreach (Verb::cases() as $verb) {
                $decision = $policy->check($ticket, $actor, $rules, $verb->value);
                $view = $offered[$verb->value] ?? null;

                if ($view instanceof TransitionView) {
                    self::assertTrue($decision->isAllowed, $context.': '.$verb->value.' is offered but refused');
                    self::assertEquals($view->moveTarget, $decision->moveTarget, $context.': '.$verb->value);
                } else {
                    self::assertFalse($decision->isAllowed, $context.': '.$verb->value.' is allowed but not offered');
                    self::assertNull($decision->moveTarget, $context.': '.$verb->value);
                }
            }

            $combinationsWithAVerb += [] === $offered ? 0 : 1;

            $groups = $policy->moveChoices($ticket, $actor, $rules, $steps)->groups;
            $combinationsWithAMoveChoice += [] === $groups ? 0 : 1;
            foreach ($groups as $group) {
                foreach ($group->choices as $choice) {
                    $decision = $policy->checkMoveTo($ticket, $actor, $rules, $choice->step);
                    $name = $context.': move to '.$choice->step->name();

                    if ($choice->isCurrent) {
                        self::assertFalse($decision->isAllowed, $name);
                    } elseif ('progress' === $ticket->coreCode && 'test' === $choice->step->coreCode() && $rules->reviewBeforeTest) {
                        self::assertFalse($decision->isAllowed, $name);
                        self::assertSame(self::REVIEW_FIRST_NOTE, $decision->ruleNote?->render(GuardFixtures::nameOf(...)), $name);
                    } else {
                        self::assertTrue($decision->isAllowed, $name);
                    }
                }
            }
        }

        self::assertGreaterThan(0, $combinationsWithAVerb);
        self::assertGreaterThan(0, $combinationsWithAMoveChoice);
    }

    private static function randomTicket(Randomizer $random): TicketState
    {
        $coreCodes = [...array_keys(self::GROUP_OF_CORE_STEP), null];
        $coreCode = $coreCodes[$random->getInt(0, \count($coreCodes) - 1)];
        $groupCode = null === $coreCode ? self::GROUPS[$random->getInt(0, \count(self::GROUPS) - 1)] : self::GROUP_OF_CORE_STEP[$coreCode];
        $user = static fn (): ?string => self::userIds()[$random->getInt(0, \count(self::userIds()) - 1)];
        $isCheckNeeded = 1 === $random->getInt(0, 1);

        return new TicketState(
            stepId: GuardFixtures::ticketStepId(),
            coreCode: $coreCode,
            groupCode: $groupCode,
            teamId: 1 === $random->getInt(0, 4) ? null : GuardFixtures::ticket('todo', 'todo')->teamId,
            assigneeId: self::userIdOrNull($user()),
            reviewerId: self::userIdOrNull($user()),
            testerId: self::userIdOrNull($user()),
            askedUserId: 1 === $random->getInt(0, 5) ? self::userIdOrNull($user()) : null,
            previousStepId: 1 === $random->getInt(0, 1) ? GuardFixtures::previousStepId() : null,
            reporterId: self::userIdOrNull($user()),
            waitingOnUserId: self::userIdOrNull($user()),
            waitingOnLabel: 1 === $random->getInt(0, 1) ? 'Supplier' : null,
            isCheckNeeded: $isCheckNeeded,
            checkerId: self::userIdOrNull($user()),
        );
    }

    /**
     * @return list<string|null> the people of GuardFixtures and nobody
     */
    private static function userIds(): array
    {
        return [null, GuardFixtures::LENA, GuardFixtures::OMAR, GuardFixtures::MIA, GuardFixtures::RAJ, GuardFixtures::SARA];
    }

    private static function userIdOrNull(?string $userId): ?UserId
    {
        return null === $userId ? null : new UserId($userId);
    }

    /**
     * @return list<ActorInTeam>
     */
    private static function people(): array
    {
        return [
            GuardFixtures::lena(),
            GuardFixtures::lena('tester'),
            GuardFixtures::omar(),
            GuardFixtures::mia(),
            GuardFixtures::raj(),
            GuardFixtures::sara(),
            GuardFixtures::viewer(),
            GuardFixtures::otherTeamMember(),
        ];
    }

    /**
     * Index 5 is In progress, the step of GuardFixtures::ticketStepId().
     *
     * @return list<StepView>
     */
    private static function steps(): array
    {
        return [
            new StepView('0190a000-0000-7000-8000-000000000101', 'Needs input', 'wait', 'Waiting', 5, 'core', 'input'),
            new StepView('0190a000-0000-7000-8000-000000000102', 'On hold', 'wait', 'Waiting', 6, 'core', 'hold'),
            new StepView('0190a000-0000-7000-8000-000000000103', 'Code review', 'check', 'Checking', 3, 'core', 'review'),
            new StepView('0190a000-0000-7000-8000-000000000104', 'Testing', 'check', 'Checking', 4, 'core', 'test'),
            new StepView('0190a000-0000-7000-8000-000000000105', 'Design check', 'check', 'Checking', 10, 'team', null),
            new StepView(GuardFixtures::ticketStepId(), 'In progress', 'doing', 'Doing', 2, 'core', 'progress'),
            new StepView('0190a000-0000-7000-8000-000000000107', 'Backlog', 'todo', 'To do', 0, 'core', 'backlog'),
            new StepView('0190a000-0000-7000-8000-000000000108', 'To do', 'todo', 'To do', 1, 'core', 'todo'),
            new StepView('0190a000-0000-7000-8000-000000000109', 'Done', 'done', 'Done', 7, 'core', 'done'),
        ];
    }

    private static function policy(): CatalogueTransitionPolicy
    {
        $moveRights = new MoveRights();

        return new CatalogueTransitionPolicy(new TransitionCatalogue(), new QuestionOpenPause(), $moveRights, new MoveSideEffects(), new TesterMovesDone($moveRights), new NoSelfReview(), new ReviewBeforeTest());
    }
}

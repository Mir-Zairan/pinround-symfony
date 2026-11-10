<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\MoveChoice;
use App\Workflow\Api\MoveChoiceGroup;
use App\Workflow\Api\MoveTarget;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where each verb takes the ticket (FR-WF-16), the buttons' labels and filled flags of the verbs of P02.T5, and the
 * lead's "Move to another step…" list.
 */
final class CatalogueTransitionPolicyMoveTest extends TestCase
{
    private const string MANAGERS_NOTE = 'Managers can read and comment. Only the team moves tickets.';
    private const string REVIEW_FIRST_NOTE = 'Team rule: code review comes before testing.';

    /**
     * @return iterable<string, array{TicketState, Verb, string, MoveTarget}> ticket, the verb Lena uses, its label,
     *                                                                        where it goes
     */
    public static function leadsMoves(): iterable
    {
        $previous = GuardFixtures::previousStepId();

        yield 'Give it to… in Backlog goes to To do' => [GuardFixtures::ticket('backlog', 'todo'), Verb::GiveItTo, 'Give it to…', MoveTarget::coreStep('todo')];
        yield 'Give it to… in To do stays in To do' => [GuardFixtures::ticket('todo', 'todo'), Verb::GiveItTo, 'Give it to…', MoveTarget::coreStep('todo')];
        yield 'Plan it goes to To do' => [GuardFixtures::ticket('backlog', 'todo'), Verb::PlanIt, 'Plan it', MoveTarget::coreStep('todo')];
        yield 'Input given with a previous step goes back to it' => [GuardFixtures::ticket('input', 'wait', previousStepId: $previous), Verb::InputGiven, 'Input given · go on', MoveTarget::step($previous)];
        yield 'Input given without a previous step goes to To do' => [GuardFixtures::ticket('input', 'wait'), Verb::InputGiven, 'Input given · go on', MoveTarget::coreStep('todo')];
        yield 'Go on with a previous step goes back to it' => [GuardFixtures::ticket('hold', 'wait', previousStepId: $previous), Verb::GoOn, 'Go on', MoveTarget::step($previous)];
        yield 'Go on without a previous step goes to In progress' => [GuardFixtures::ticket('hold', 'wait'), Verb::GoOn, 'Go on', MoveTarget::coreStep('progress')];
        yield 'Reopen goes to In progress' => [GuardFixtures::ticket('done', 'done'), Verb::Reopen, 'Reopen', MoveTarget::coreStep('progress')];
        yield 'Put on hold goes to On hold' => [GuardFixtures::ticket('progress', 'doing', previousStepId: $previous), Verb::PutOnHold, 'Put on hold', MoveTarget::coreStep('hold')];
        yield 'Put on hold from a team step goes to On hold' => [GuardFixtures::ticket(null, 'check'), Verb::PutOnHold, 'Put on hold', MoveTarget::coreStep('hold')];
        yield 'Move on from a To do step goes to In progress' => [GuardFixtures::ticket(null, 'todo'), Verb::MoveOn, 'Move on to In progress', MoveTarget::coreStep('progress')];
        yield 'Move on from a Doing step goes to Code review' => [GuardFixtures::ticket(null, 'doing'), Verb::MoveOn, 'Move on to Code review', MoveTarget::coreStep('review')];
        yield 'Move on from a Checking step goes to Testing' => [GuardFixtures::ticket(null, 'check'), Verb::MoveOn, 'Move on to Testing', MoveTarget::coreStep('test')];
        yield 'Move on from a Waiting step with a previous step goes back to it' => [GuardFixtures::ticket(null, 'wait', previousStepId: $previous), Verb::MoveOn, 'Move on to the previous step', MoveTarget::step($previous)];
        yield 'Move on from a Waiting step without a previous step goes to In progress' => [GuardFixtures::ticket(null, 'wait'), Verb::MoveOn, 'Move on to In progress', MoveTarget::coreStep('progress')];
        yield 'Send to testing goes to Testing' => [GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::LENA), Verb::SendToTesting, 'Send to testing', MoveTarget::coreStep('test')];
    }

    #[Test]
    #[DataProvider('leadsMoves')]
    public function everyAllowedVerbCarriesItsMoveTargetInAvailableAndInCheck(TicketState $ticket, Verb $verb, string $label, MoveTarget $target): void
    {
        $policy = self::policy();
        $rules = GuardFixtures::rules(reviewBeforeTest: false);

        $view = array_values(array_filter(
            $policy->available($ticket, GuardFixtures::lena(), $rules)->transitions,
            static fn (TransitionView $transition): bool => $transition->verb === $verb->value,
        ));
        $decision = $policy->check($ticket, GuardFixtures::lena(), $rules, $verb->value);

        self::assertCount(1, $view);
        self::assertSame($label, $view[0]->label);
        self::assertEquals($target, $view[0]->moveTarget);
        self::assertTrue($decision->isAllowed);
        self::assertEquals($target, $decision->moveTarget);
        self::assertNull($decision->ruleNote);
    }

    #[Test]
    public function aRefusedDecisionCarriesNoMoveTarget(): void
    {
        $decision = self::policy()->check(GuardFixtures::ticket('hold', 'wait'), GuardFixtures::omar(), GuardFixtures::rules(), Verb::GoOn->value);

        self::assertFalse($decision->isAllowed);
        self::assertNull($decision->moveTarget);
    }

    #[Test]
    public function theAssigneeOfATicketOnHoldCannotGoOn(): void
    {
        $ticket = GuardFixtures::ticket('hold', 'wait', assignee: GuardFixtures::OMAR);

        self::assertSame([], self::policy()->available($ticket, GuardFixtures::omar(), GuardFixtures::rules())->transitions);
    }

    /**
     * @return iterable<string, array{TicketState, ActorInTeam, list<array{string, bool}>}> ticket, person, the verbs
     *                                                                                      they see with whether each is filled
     */
    public static function buttonsWithTheirFilledFlag(): iterable
    {
        yield 'Needs input, the lead: Input given filled, Put on hold outline' => [GuardFixtures::ticket('input', 'wait', waitingOnUser: GuardFixtures::MIA), GuardFixtures::lena(), [['input_given', true], ['put_on_hold', false]]];
        yield 'Needs input, the person waited on' => [GuardFixtures::ticket('input', 'wait', waitingOnUser: GuardFixtures::MIA), GuardFixtures::mia(), [['input_given', true]]];
        yield 'On hold, the lead: Go on filled, Put on hold not offered' => [GuardFixtures::ticket('hold', 'wait'), GuardFixtures::lena(), [['go_on', true]]];
        yield 'Done, the lead: Reopen is outline even alone' => [GuardFixtures::ticket('done', 'done'), GuardFixtures::lena(), [['reopen', false]]];
        yield 'In progress, the assignee, review first off: only Send to code review filled' => [GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR), GuardFixtures::omar(), [['send_to_code_review', true], ['send_to_testing', false]]];
        yield 'A team step, the lead: Put on hold outline, Move on filled' => [GuardFixtures::ticket(null, 'check', assignee: GuardFixtures::OMAR), GuardFixtures::lena(), [['put_on_hold', false], ['move_on', true]]];
        yield 'A team step, the assignee: Move on filled' => [GuardFixtures::ticket(null, 'check', assignee: GuardFixtures::OMAR), GuardFixtures::omar(), [['move_on', true]]];
    }

    /**
     * @param list<array{string, bool}> $expected
     */
    #[Test]
    #[DataProvider('buttonsWithTheirFilledFlag')]
    public function atMostOneButtonIsFilledAndItIsTheFirstPrimaryVerbOffered(TicketState $ticket, ActorInTeam $actor, array $expected): void
    {
        $buttons = self::policy()->available($ticket, $actor, GuardFixtures::rules(reviewBeforeTest: false))->transitions;

        self::assertSame($expected, array_map(static fn (TransitionView $button): array => [$button->verb, $button->isPrimary], $buttons));
    }

    #[Test]
    public function withReviewFirstOnSendToTestingIsHiddenWithoutANoteButCheckRefusesItWithTheRulesNote(): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);
        $policy = self::policy();

        $availability = $policy->available($ticket, GuardFixtures::omar(), GuardFixtures::rules());
        $decision = $policy->check($ticket, GuardFixtures::omar(), GuardFixtures::rules(), Verb::SendToTesting->value);

        self::assertSame(['send_to_code_review'], array_map(static fn (TransitionView $button): string => $button->verb, $availability->transitions));
        self::assertNull($availability->ruleNote);
        self::assertFalse($decision->isAllowed);
        self::assertSame(self::REVIEW_FIRST_NOTE, $decision->ruleNote?->render(GuardFixtures::nameOf(...)));
    }

    /**
     * The guards of "Move on to…" are those of the core move into the same step, and no guard stops a move into Testing
     * from a Checking step: review_before_test is about In progress → Testing, and no_self_review about Code review →
     * Testing, and neither is the step the ticket leaves.
     */
    #[Test]
    public function withEveryRuleOnNothingBlocksMoveOnFromACheckingStepIntoTesting(): void
    {
        $ticket = GuardFixtures::ticket(null, 'check', assignee: GuardFixtures::LENA, reviewer: GuardFixtures::OMAR);
        $policy = self::policy();

        $availability = $policy->available($ticket, GuardFixtures::lena(), GuardFixtures::rules());
        $decision = $policy->check($ticket, GuardFixtures::lena(), GuardFixtures::rules(), Verb::MoveOn->value);

        self::assertContains('move_on', array_map(static fn (TransitionView $button): string => $button->verb, $availability->transitions));
        self::assertNull($availability->ruleNote);
        self::assertTrue($decision->isAllowed);
        self::assertEquals(MoveTarget::coreStep('test'), $decision->moveTarget);
    }

    #[Test]
    public function aCompanyStepWithAnUnknownGroupCodeIsRefusedLoudly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"sideways" is not a group.');

        self::policy()->available(GuardFixtures::ticket(null, 'sideways'), GuardFixtures::lena(), GuardFixtures::rules());
    }

    #[Test]
    public function moveChoicesAreGroupedInTheOrderTheStepsComeWithTheCurrentStepMarked(): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);

        $choices = self::policy()->moveChoices($ticket, GuardFixtures::lena(), GuardFixtures::rules(), self::steps());

        self::assertNull($choices->ruleNote);
        self::assertSame(
            [
                ['wait', 'Waiting', [['Needs input', false], ['On hold', false]]],
                ['check', 'Checking', [['Code review', false], ['Testing', false], ['Design check', false]]],
                ['doing', 'Doing', [['In progress', true]]],
                ['todo', 'To do', [['Backlog', false], ['To do', false]]],
                ['done', 'Done', [['Done', false]]],
            ],
            array_map(
                static fn (MoveChoiceGroup $group): array => [
                    $group->groupCode,
                    $group->groupLabel,
                    array_map(static fn (MoveChoice $choice): array => [$choice->step->name(), $choice->isCurrent], $group->choices),
                ],
                $choices->groups,
            ),
        );
    }

    #[Test]
    public function aTicketInATeamStepMarksThatStepCurrent(): void
    {
        $steps = self::steps();
        $ticket = new TicketState($steps[4]->id(), null, 'check', GuardFixtures::ticket('todo', 'todo')->teamId);

        $choices = self::policy()->moveChoices($ticket, GuardFixtures::lena(), GuardFixtures::rules(), $steps);

        $current = [];
        foreach ($choices->groups as $group) {
            foreach ($group->choices as $choice) {
                if ($choice->isCurrent) {
                    $current[] = $choice->step->name();
                }
            }
        }

        self::assertSame(['Design check'], $current);
    }

    /**
     * @return iterable<string, array{ActorInTeam, TicketState, string|null}> person, ticket, the note
     */
    public static function peopleWithoutAMoveList(): iterable
    {
        $inProgress = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);

        yield 'Omar, a member' => [GuardFixtures::omar(), $inProgress, null];
        yield 'Mia, a tester' => [GuardFixtures::mia(), $inProgress, null];
        yield 'Raj, a manager' => [GuardFixtures::raj(), $inProgress, self::MANAGERS_NOTE];
        yield 'Sara, an admin' => [GuardFixtures::sara(), $inProgress, self::MANAGERS_NOTE];
        yield 'a viewer' => [GuardFixtures::viewer(), $inProgress, self::MANAGERS_NOTE];
        yield 'a member of another team' => [GuardFixtures::otherTeamMember(), $inProgress, self::MANAGERS_NOTE];
        yield 'Lena, on a ticket no team owns' => [GuardFixtures::lena(), GuardFixtures::ticket('progress', 'doing', hasTeam: false), self::MANAGERS_NOTE];
        yield 'Lena, with a question open' => [GuardFixtures::lena(), GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR, asked: GuardFixtures::MIA), 'Paused until Mia answers the question below.'];
    }

    #[Test]
    #[DataProvider('peopleWithoutAMoveList')]
    public function onlyTheLeadGetsTheMoveListAndNotWhileAQuestionIsOpen(ActorInTeam $actor, TicketState $ticket, ?string $expectedNote): void
    {
        $choices = self::policy()->moveChoices($ticket, $actor, GuardFixtures::rules(), self::steps());

        self::assertSame([], $choices->groups);
        self::assertSame($expectedNote, $choices->ruleNote?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function inProgressToTestingIsRefusedWhileReviewFirstIsOnWithTheRulesNote(): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);

        $decision = self::policy()->checkMoveTo($ticket, GuardFixtures::lena(), GuardFixtures::rules(), self::steps()[3]);

        self::assertFalse($decision->isAllowed);
        self::assertSame(self::REVIEW_FIRST_NOTE, $decision->ruleNote?->render(GuardFixtures::nameOf(...)));
        self::assertNull($decision->moveTarget);
    }

    #[Test]
    public function inProgressToTestingIsAllowedWithReviewFirstOffAndGoesToThatStep(): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);
        $testing = self::steps()[3];

        $decision = self::policy()->checkMoveTo($ticket, GuardFixtures::lena(), GuardFixtures::rules(reviewBeforeTest: false), $testing);

        self::assertTrue($decision->isAllowed);
        self::assertEquals(MoveTarget::step($testing->id()), $decision->moveTarget);
    }

    #[Test]
    public function reviewFirstOnlyStopsTestingFromInProgress(): void
    {
        $steps = self::steps();
        $fromReview = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::OMAR);
        $fromProgress = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);

        self::assertTrue(self::policy()->checkMoveTo($fromReview, GuardFixtures::lena(), GuardFixtures::rules(), $steps[3])->isAllowed);
        self::assertTrue(self::policy()->checkMoveTo($fromProgress, GuardFixtures::lena(), GuardFixtures::rules(), $steps[2])->isAllowed);
        self::assertTrue(self::policy()->checkMoveTo($fromProgress, GuardFixtures::lena(), GuardFixtures::rules(), $steps[4])->isAllowed);
    }

    #[Test]
    #[DataProvider('peopleWithoutAMoveList')]
    public function nobodyButTheLeadMayMoveToAnotherStepAndNotWhileAQuestionIsOpen(ActorInTeam $actor, TicketState $ticket, ?string $expectedNote): void
    {
        $decision = self::policy()->checkMoveTo($ticket, $actor, GuardFixtures::rules(reviewBeforeTest: false), self::steps()[0]);

        self::assertFalse($decision->isAllowed);
        self::assertSame($expectedNote, $decision->ruleNote?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function movingToTheStepTheTicketIsInIsRefusedWithoutANote(): void
    {
        $steps = self::steps();
        $ticket = new TicketState($steps[5]->id(), 'progress', 'doing', GuardFixtures::ticket('todo', 'todo')->teamId);

        $decision = self::policy()->checkMoveTo($ticket, GuardFixtures::lena(), GuardFixtures::rules(), $steps[5]);

        self::assertFalse($decision->isAllowed);
        self::assertNull($decision->ruleNote);
    }

    /**
     * Wait, Check and Doing before To do and Done, as StepQueries::moveTargetsFor orders them; index 5 is In progress,
     * the step of GuardFixtures::ticketStepId().
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

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\Availability;
use App\Workflow\Api\MoveTarget;
use App\Workflow\Api\RuleSetView;
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

final class CatalogueTransitionPolicyTest extends TestCase
{
    private const string MANAGERS_NOTE = 'Managers can read and comment. Only the team moves tickets.';

    /**
     * Each catalogue row with a ticket in its from step, crossed with every person of GuardFixtures; all three rules
     * are on unless the row says otherwise.
     *
     * @return iterable<string, array{string, TicketState, RuleSetView, ActorInTeam, bool}> verb, ticket, rules, actor,
     *                                                                                      whether they see the verb
     */
    public static function catalogueRowsByRole(): iterable
    {
        $rows = [
            'Plan it' => [Verb::PlanIt, GuardFixtures::ticket('backlog', 'todo'), GuardFixtures::rules(), ['Lena']],
            'Give it to… in Backlog' => [Verb::GiveItTo, GuardFixtures::ticket('backlog', 'todo'), GuardFixtures::rules(), ['Lena']],
            'Give it to… in To do, Omar checks the new ticket' => [Verb::GiveItTo, GuardFixtures::ticket('todo', 'todo', checker: GuardFixtures::OMAR), GuardFixtures::rules(), ['Lena', 'Omar']],
            'Start work, given to Omar' => [Verb::StartWork, GuardFixtures::ticket('todo', 'todo', assignee: GuardFixtures::OMAR), GuardFixtures::rules(), ['Omar']],
            'Send to code review, Omar builds it' => [Verb::SendToCodeReview, GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR), GuardFixtures::rules(), ['Omar']],
            'Send to code review, nobody assigned' => [Verb::SendToCodeReview, GuardFixtures::ticket('progress', 'doing'), GuardFixtures::rules(), ['Lena']],
            'Approve code, Lena reviews Omar\'s code' => [Verb::ApproveCode, self::omarsTicketInCodeReview(), GuardFixtures::rules(), ['Lena']],
            'Ask for changes, Lena reviews Omar\'s code' => [Verb::AskForChanges, self::omarsTicketInCodeReview(), GuardFixtures::rules(), ['Lena']],
            'Pass it, rule tester_moves_done on' => [Verb::PassIt, self::omarsTicketInTesting(), GuardFixtures::rules(), ['Mia']],
            'Pass it, rule tester_moves_done off' => [Verb::PassIt, self::omarsTicketInTesting(), GuardFixtures::rules(testerMovesDone: false), ['Lena', 'Mia']],
            'Needs changes' => [Verb::NeedsChanges, self::omarsTicketInTesting(), GuardFixtures::rules(), ['Mia']],
            'Send to testing, review_before_test off' => [Verb::SendToTesting, GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR), GuardFixtures::rules(reviewBeforeTest: false), ['Omar']],
            'Send to testing, review_before_test on' => [Verb::SendToTesting, GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR), GuardFixtures::rules(), []],
            'Send to testing, nobody assigned, rule off' => [Verb::SendToTesting, GuardFixtures::ticket('progress', 'doing'), GuardFixtures::rules(reviewBeforeTest: false), []],
            'Input given, waiting on Mia' => [Verb::InputGiven, GuardFixtures::ticket('input', 'wait', assignee: GuardFixtures::OMAR, reporter: GuardFixtures::OMAR, waitingOnUser: GuardFixtures::MIA), GuardFixtures::rules(), ['Lena', 'Mia']],
            'Input given, waiting on the reporter Omar' => [Verb::InputGiven, GuardFixtures::ticket('input', 'wait', reporter: GuardFixtures::OMAR), GuardFixtures::rules(), ['Lena', 'Omar']],
            'Input given, waiting on a customer' => [Verb::InputGiven, GuardFixtures::ticket('input', 'wait', reporter: GuardFixtures::OMAR, waitingOnLabel: 'Customer'), GuardFixtures::rules(), ['Lena']],
            'Go on' => [Verb::GoOn, GuardFixtures::ticket('hold', 'wait', assignee: GuardFixtures::OMAR), GuardFixtures::rules(), ['Lena']],
            'Reopen' => [Verb::Reopen, GuardFixtures::ticket('done', 'done', assignee: GuardFixtures::OMAR), GuardFixtures::rules(), ['Lena', 'Mia']],
            'Reopen, tester_moves_done off' => [Verb::Reopen, GuardFixtures::ticket('done', 'done', assignee: GuardFixtures::OMAR), GuardFixtures::rules(testerMovesDone: false), ['Lena', 'Mia']],
            'Put on hold in In progress' => [Verb::PutOnHold, GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR), GuardFixtures::rules(), ['Lena']],
            'Put on hold in Needs input' => [Verb::PutOnHold, GuardFixtures::ticket('input', 'wait', waitingOnLabel: 'Customer'), GuardFixtures::rules(), ['Lena']],
            'Put on hold in a team step of Checking' => [Verb::PutOnHold, GuardFixtures::ticket(null, 'check', assignee: GuardFixtures::OMAR), GuardFixtures::rules(), ['Lena']],
            'Put on hold in On hold' => [Verb::PutOnHold, GuardFixtures::ticket('hold', 'wait'), GuardFixtures::rules(), []],
            'Put on hold in Done' => [Verb::PutOnHold, GuardFixtures::ticket('done', 'done'), GuardFixtures::rules(), []],
            'Put on hold in a company step of the Done group' => [Verb::PutOnHold, GuardFixtures::ticket(null, 'done'), GuardFixtures::rules(), []],
            'Move on in a team step of Checking' => [Verb::MoveOn, GuardFixtures::ticket(null, 'check', assignee: GuardFixtures::OMAR), GuardFixtures::rules(), ['Lena', 'Omar']],
            'Move on in a team step of Waiting' => [Verb::MoveOn, GuardFixtures::ticket(null, 'wait', assignee: GuardFixtures::OMAR), GuardFixtures::rules(), ['Lena', 'Omar']],
            'Move on in a core step' => [Verb::MoveOn, GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR), GuardFixtures::rules(), []],
            'Move on in a company step of the Done group' => [Verb::MoveOn, GuardFixtures::ticket(null, 'done', assignee: GuardFixtures::OMAR), GuardFixtures::rules(), []],
        ];

        foreach ($rows as $rowName => [$verb, $ticket, $rules, $seers]) {
            foreach (self::everyone() as $actorName => $actor) {
                yield $rowName.' × '.$actorName => [$verb->value, $ticket, $rules, $actor, \in_array($actorName, $seers, true)];
            }
        }
    }

    #[Test]
    #[DataProvider('catalogueRowsByRole')]
    public function eachCatalogueRowIsOfferedToItsMoversOnlyAndCheckAgrees(string $verb, TicketState $ticket, RuleSetView $rules, ActorInTeam $actor, bool $isOffered): void
    {
        $policy = self::policy();

        self::assertSame($isOffered, \in_array($verb, self::verbsOf($policy->available($ticket, $actor, $rules)), true));
        self::assertSame($isOffered, $policy->check($ticket, $actor, $rules, $verb)->isAllowed);
    }

    #[Test]
    public function withNoSelfReviewOnLenaAsTheAssigneeGetsNoApproveCodeAndANoteNamingOmar(): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::LENA, reviewer: GuardFixtures::OMAR, tester: GuardFixtures::MIA);
        $policy = self::policy();

        $availability = $policy->available($ticket, GuardFixtures::lena(), GuardFixtures::rules());
        $decision = $policy->check($ticket, GuardFixtures::lena(), GuardFixtures::rules(), Verb::ApproveCode->value);

        self::assertSame([Verb::PutOnHold->value], self::verbsOf($availability));
        self::assertSame('Team rule: nobody approves their own code. Omar reviews it.', $availability->ruleNote?->render(GuardFixtures::nameOf(...)));
        self::assertFalse($decision->isAllowed);
        self::assertNull($decision->moveTarget);
        self::assertSame('Team rule: nobody approves their own code. Omar reviews it.', $decision->ruleNote?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function omarAsTheReviewerOfLenasCodeGetsBothVerbsAndNoNote(): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::LENA, reviewer: GuardFixtures::OMAR, tester: GuardFixtures::MIA);

        $availability = self::policy()->available($ticket, GuardFixtures::omar(), GuardFixtures::rules());

        self::assertSame([Verb::ApproveCode->value, Verb::AskForChanges->value], self::verbsOf($availability));
        self::assertNull($availability->ruleNote);
    }

    #[Test]
    public function whenLenaReviewsHerOwnCodeForWantOfAnotherDeveloperSheMayApproveIt(): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::LENA, reviewer: GuardFixtures::LENA);

        $availability = self::policy()->available($ticket, GuardFixtures::lena(), GuardFixtures::rules());

        self::assertSame([Verb::ApproveCode->value, Verb::AskForChanges->value, Verb::PutOnHold->value], self::verbsOf($availability));
        self::assertNull($availability->ruleNote);
    }

    #[Test]
    public function inToDoGiveItToIsOfferedOnlyWhileNobodyIsAssigned(): void
    {
        $ticket = GuardFixtures::ticket('todo', 'todo', assignee: GuardFixtures::OMAR);
        $policy = self::policy();

        self::assertSame([Verb::PutOnHold->value], self::verbsOf($policy->available($ticket, GuardFixtures::lena(), GuardFixtures::rules())));
        self::assertSame([Verb::StartWork->value], self::verbsOf($policy->available($ticket, GuardFixtures::omar(), GuardFixtures::rules())));
    }

    #[Test]
    public function aCheckerGetsGiveItToOnlyWhileTheCheckIsNeeded(): void
    {
        $whileChecked = GuardFixtures::ticket('todo', 'todo', checker: GuardFixtures::OMAR);
        $afterTheCheck = new TicketState($whileChecked->stepId, 'todo', 'todo', $whileChecked->teamId, isCheckNeeded: false, checkerId: $whileChecked->checkerId);

        self::assertSame([Verb::GiveItTo->value], self::verbsOf(self::policy()->available($whileChecked, GuardFixtures::omar(), GuardFixtures::rules())));
        self::assertSame([], self::verbsOf(self::policy()->available($afterTheCheck, GuardFixtures::omar(), GuardFixtures::rules())));
    }

    #[Test]
    public function theTicketsTesterWithoutTheJobTesterMaySayNeedsChangesButNotPassIt(): void
    {
        $ticket = GuardFixtures::ticket('test', 'check', assignee: GuardFixtures::LENA, tester: GuardFixtures::OMAR);

        $availability = self::policy()->available($ticket, GuardFixtures::omar(), GuardFixtures::rules());

        self::assertSame([Verb::NeedsChanges->value], self::verbsOf($availability));
        self::assertSame('Team rule: only the tester moves a ticket to Done.', $availability->ruleNote?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function aPersonWhoIsNotATesterSeesTheDoneRuleInTesting(): void
    {
        $policy = self::policy();

        $availability = $policy->available(self::omarsTicketInTesting(), GuardFixtures::lena(), GuardFixtures::rules());
        $decision = $policy->check(self::omarsTicketInTesting(), GuardFixtures::lena(), GuardFixtures::rules(), Verb::PassIt->value);

        self::assertSame([Verb::PutOnHold->value], self::verbsOf($availability));
        self::assertSame('Team rule: only the tester moves a ticket to Done.', $availability->ruleNote?->render(GuardFixtures::nameOf(...)));
        self::assertFalse($decision->isAllowed);
        self::assertSame('Team rule: only the tester moves a ticket to Done.', $decision->ruleNote?->render(GuardFixtures::nameOf(...)));
    }

    /**
     * @return iterable<string, array{ActorInTeam}>
     */
    public static function teamMovers(): iterable
    {
        yield 'Omar, the assignee' => [GuardFixtures::omar()];
        yield 'Mia, the asked person' => [GuardFixtures::mia()];
        yield 'Lena, the lead' => [GuardFixtures::lena()];
    }

    #[Test]
    #[DataProvider('teamMovers')]
    public function whileAQuestionIsOpenNobodyMovesTheTicketTheAskedPersonIncluded(ActorInTeam $actor): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR, asked: GuardFixtures::MIA);
        $policy = self::policy();

        $availability = $policy->available($ticket, $actor, GuardFixtures::rules());
        $decision = $policy->check($ticket, $actor, GuardFixtures::rules(), Verb::SendToCodeReview->value);

        self::assertSame([], $availability->transitions);
        self::assertSame('Paused until Mia answers the question below.', $availability->ruleNote?->render(GuardFixtures::nameOf(...)));
        self::assertFalse($decision->isAllowed);
        self::assertSame('Paused until Mia answers the question below.', $decision->ruleNote?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function aQuestionOpenInAStepNoVerbLeavesStillPausesTheTicket(): void
    {
        $ticket = GuardFixtures::ticket(null, 'check', assignee: GuardFixtures::OMAR, asked: GuardFixtures::MIA);

        $availability = self::policy()->available($ticket, GuardFixtures::omar(), GuardFixtures::rules());

        self::assertSame('Paused until Mia answers the question below.', $availability->ruleNote?->render(GuardFixtures::nameOf(...)));
    }

    /**
     * @return iterable<string, array{ActorInTeam, TicketState}>
     */
    public static function peopleWhoDoNotMoveTickets(): iterable
    {
        $omarsTicket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);

        yield 'Raj, a manager' => [GuardFixtures::raj(), $omarsTicket];
        yield 'Sara, an admin' => [GuardFixtures::sara(), $omarsTicket];
        yield 'a viewer with the job tester' => [GuardFixtures::viewer(), $omarsTicket];
        yield 'a member of another team' => [GuardFixtures::otherTeamMember(), $omarsTicket];
        yield 'Lena, on a ticket no team owns' => [GuardFixtures::lena(), GuardFixtures::ticket('progress', 'doing', hasTeam: false)];
        yield 'Raj, with a question open' => [GuardFixtures::raj(), GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR, asked: GuardFixtures::MIA)];
    }

    #[Test]
    #[DataProvider('peopleWhoDoNotMoveTickets')]
    public function withoutTheTeamRoleLeadOrMemberNobodyGetsAVerbAndTheManagersNoteWins(ActorInTeam $actor, TicketState $ticket): void
    {
        $policy = self::policy();

        $availability = $policy->available($ticket, $actor, GuardFixtures::rules());
        $decision = $policy->check($ticket, $actor, GuardFixtures::rules(), Verb::SendToCodeReview->value);

        self::assertSame([], $availability->transitions);
        self::assertSame(self::MANAGERS_NOTE, $availability->ruleNote?->render(GuardFixtures::nameOf(...)));
        self::assertFalse($decision->isAllowed);
        self::assertSame(self::MANAGERS_NOTE, $decision->ruleNote?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function anUnknownVerbIsRefusedWithoutANote(): void
    {
        $decision = self::policy()->check(GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR), GuardFixtures::omar(), GuardFixtures::rules(), 'send_to_the_moon');

        self::assertFalse($decision->isAllowed);
        self::assertNull($decision->ruleNote);
    }

    #[Test]
    public function aVerbThatDoesNotApplyInTheTicketsStepIsRefusedWithoutANote(): void
    {
        $decision = self::policy()->check(GuardFixtures::ticket('progress', 'doing'), GuardFixtures::lena(), GuardFixtures::rules(), Verb::PlanIt->value);

        self::assertFalse($decision->isAllowed);
        self::assertNull($decision->ruleNote);
    }

    #[Test]
    public function theButtonsComeInCatalogueOrderWithTheFirstOneFilled(): void
    {
        $availability = self::policy()->available(self::omarsTicketInCodeReview(), GuardFixtures::lena(), GuardFixtures::rules());

        self::assertEquals([
            new TransitionView('approve_code', 'Approve code', true, MoveTarget::coreStep('test')),
            new TransitionView('ask_for_changes', 'Ask for changes', false, MoveTarget::coreStep('progress')),
            new TransitionView('put_on_hold', 'Put on hold', false, MoveTarget::coreStep('hold')),
        ], $availability->transitions);
    }

    #[Test]
    public function inBacklogOnlyPlanItIsFilledThoughGiveItToIsPrimaryInTheCatalogue(): void
    {
        $availability = self::policy()->available(GuardFixtures::ticket('backlog', 'todo'), GuardFixtures::lena(), GuardFixtures::rules());

        self::assertEquals([
            new TransitionView('plan_it', 'Plan it', true, MoveTarget::coreStep('todo')),
            new TransitionView('give_it_to', 'Give it to…', false, MoveTarget::coreStep('todo')),
            new TransitionView('put_on_hold', 'Put on hold', false, MoveTarget::coreStep('hold')),
        ], $availability->transitions);
    }

    #[Test]
    public function aVerbTheCatalogueMarksOutlineStaysOutlineWhenItIsTheOnlyButton(): void
    {
        $ticket = GuardFixtures::ticket('test', 'check', assignee: GuardFixtures::LENA, tester: GuardFixtures::OMAR);

        $availability = self::policy()->available($ticket, GuardFixtures::omar(), GuardFixtures::rules());

        self::assertEquals([new TransitionView('needs_changes', 'Needs changes', false, MoveTarget::coreStep('progress'))], $availability->transitions);
    }

    #[Test]
    public function aMemberWhoIsNeitherLeadNorAssigneeHasNoVerbAndNoNoteInACompanyOrTeamStep(): void
    {
        $ticket = GuardFixtures::ticket(null, 'check', assignee: GuardFixtures::OMAR);
        $policy = self::policy();

        $availability = $policy->available($ticket, GuardFixtures::mia(), GuardFixtures::rules());
        $decision = $policy->check($ticket, GuardFixtures::lena(), GuardFixtures::rules(), Verb::ApproveCode->value);

        self::assertSame([], $availability->transitions);
        self::assertNull($availability->ruleNote);
        self::assertFalse($decision->isAllowed);
        self::assertNull($decision->ruleNote);
    }

    #[Test]
    public function availableRefusesACoreCodeThatIsNoCoreStep(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"nowhere" is not a core step.');

        self::policy()->available(GuardFixtures::ticket('nowhere', 'doing'), GuardFixtures::lena(), GuardFixtures::rules());
    }

    #[Test]
    public function checkOfAKnownVerbRefusesACoreCodeThatIsNoCoreStep(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('"nowhere" is not a core step.');

        self::policy()->check(GuardFixtures::ticket('nowhere', 'doing'), GuardFixtures::lena(), GuardFixtures::rules(), Verb::PlanIt->value);
    }

    private static function policy(): CatalogueTransitionPolicy
    {
        $moveRights = new MoveRights();

        return new CatalogueTransitionPolicy(new TransitionCatalogue(), new QuestionOpenPause(), $moveRights, new MoveSideEffects(), new TesterMovesDone($moveRights), new NoSelfReview(), new ReviewBeforeTest());
    }

    /**
     * @return array<string, ActorInTeam>
     */
    private static function everyone(): array
    {
        return [
            'Lena' => GuardFixtures::lena(),
            'Omar' => GuardFixtures::omar(),
            'Mia' => GuardFixtures::mia(),
            'Raj' => GuardFixtures::raj(),
            'Sara' => GuardFixtures::sara(),
            'viewer' => GuardFixtures::viewer(),
            'other team member' => GuardFixtures::otherTeamMember(),
        ];
    }

    private static function omarsTicketInCodeReview(): TicketState
    {
        return GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::OMAR, reviewer: GuardFixtures::LENA, tester: GuardFixtures::MIA);
    }

    private static function omarsTicketInTesting(): TicketState
    {
        return GuardFixtures::ticket('test', 'check', assignee: GuardFixtures::OMAR, reviewer: GuardFixtures::LENA, tester: GuardFixtures::MIA);
    }

    /**
     * @return list<string>
     */
    private static function verbsOf(Availability $availability): array
    {
        return array_map(static fn (TransitionView $transition): string => $transition->verb, $availability->transitions);
    }
}

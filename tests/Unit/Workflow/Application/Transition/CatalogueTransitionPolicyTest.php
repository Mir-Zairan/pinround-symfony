<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\Availability;
use App\Workflow\Api\RuleSetView;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\TransitionView;
use App\Workflow\Application\Transition\CatalogueTransitionPolicy;
use App\Workflow\Application\Transition\NoSelfReview;
use App\Workflow\Application\Transition\QuestionOpenPause;
use App\Workflow\Application\Transition\ReviewBeforeTest;
use App\Workflow\Application\Transition\TesterMovesDone;
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

        self::assertSame([], self::verbsOf($availability));
        self::assertSame('Team rule: nobody approves their own code. Omar reviews it.', $availability->ruleNote?->render(GuardFixtures::nameOf(...)));
        self::assertFalse($decision->isAllowed);
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

        self::assertSame([Verb::ApproveCode->value, Verb::AskForChanges->value], self::verbsOf($availability));
        self::assertNull($availability->ruleNote);
    }

    #[Test]
    public function inToDoGiveItToIsOfferedOnlyWhileNobodyIsAssigned(): void
    {
        $ticket = GuardFixtures::ticket('todo', 'todo', assignee: GuardFixtures::OMAR);
        $policy = self::policy();

        self::assertSame([], self::verbsOf($policy->available($ticket, GuardFixtures::lena(), GuardFixtures::rules())));
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
    public function theTicketsTesterWithoutTheJobTesterMayAskForChangesButNotPassIt(): void
    {
        $ticket = GuardFixtures::ticket('test', 'check', assignee: GuardFixtures::LENA, tester: GuardFixtures::OMAR);

        $availability = self::policy()->available($ticket, GuardFixtures::omar(), GuardFixtures::rules());

        self::assertSame([Verb::NeedsChanges->value], self::verbsOf($availability));
        self::assertSame('Team rule: only the tester moves a ticket to Done.', $availability->ruleNote?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function aPersonWhoIsNotATesterSeesTheDoneRuleInTesting(): void
    {
        $availability = self::policy()->available(self::omarsTicketInTesting(), GuardFixtures::lena(), GuardFixtures::rules());

        self::assertSame([], self::verbsOf($availability));
        self::assertSame('Team rule: only the tester moves a ticket to Done.', $availability->ruleNote?->render(GuardFixtures::nameOf(...)));
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
    public function aVerbThatDoesNotLeaveTheTicketsStepIsRefusedWithoutANote(): void
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
            new TransitionView('approve_code', 'Approve code', true),
            new TransitionView('ask_for_changes', 'Ask for changes', false),
        ], $availability->transitions);
    }

    #[Test]
    public function aCompanyOrTeamStepHasNoVerbAndNoNote(): void
    {
        $ticket = GuardFixtures::ticket(null, 'check', assignee: GuardFixtures::OMAR);
        $policy = self::policy();

        $availability = $policy->available($ticket, GuardFixtures::lena(), GuardFixtures::rules());
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
        return new CatalogueTransitionPolicy(new TransitionCatalogue(), new QuestionOpenPause(), new TesterMovesDone(), new NoSelfReview(), new ReviewBeforeTest());
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

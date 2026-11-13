<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Ticketing\Application\FixedStepQueries;
use App\Tests\Unit\Workflow\Application\Transition\GuardFixtures;
use App\Ticketing\UI\NextStep;
use App\Ticketing\UI\NextStepChoices;
use App\Ticketing\UI\PageTurn;
use App\Workflow\Api\ActorInTeam;
use App\Workflow\Api\MoveChoice;
use App\Workflow\Api\MoveChoiceGroup;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\TransitionView;
use App\Workflow\Api\Turn;
use App\Workflow\Application\Turn\PlainNowNext;
use App\Workflow\Application\Turn\PlainVerbFor;
use App\Workflow\Application\Turn\ReviewerChoice;
use App\Workflow\Application\Turn\TesterChoice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The "Your next step" box of the Shop team of Acme Foods, with the real transition policy and sentences: Lena leads
 * and develops, Omar develops, Mia tests; Raj is a manager without a team role. Nothing here reads the clock.
 */
final class NextStepChoicesTest extends TestCase
{
    private const string ORGANIZATION = '0190a000-0000-7000-8000-0000000000f1';
    private const string MANAGERS_NOTE = 'Managers can read and comment. Only the team moves tickets.';

    /** Raj has no name here, as for a person whose user row is gone. */
    private const array NAMES = [GuardFixtures::LENA => 'Lena', GuardFixtures::OMAR => 'Omar', GuardFixtures::MIA => 'Mia'];

    private RecordingTransitionPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new RecordingTransitionPolicy(GuardFixtures::policy());
    }

    #[Test]
    public function aDoneTicketSaysNothingIsLeftForAnyone(): void
    {
        $pageTurn = self::pageTurn(GuardFixtures::ticket('done', 'done', assignee: GuardFixtures::OMAR), Turn::nobody());

        self::assertSame('Nothing. This ticket is done.', $this->nextStepOf($pageTurn, self::lead(GuardFixtures::LENA))->sentence);
        self::assertSame('Nothing. This ticket is done.', $this->nextStepOf($pageTurn, self::manager(), mayMove: false)->sentence);
    }

    #[Test]
    public function theCheckerOfANewTicketIsAskedForAQuickLookAndOthersSeeWhoseTurnItIs(): void
    {
        $pageTurn = self::pageTurn(GuardFixtures::ticket('backlog', 'todo', checker: GuardFixtures::MIA), new Turn(new UserId(GuardFixtures::MIA), null, NeedsYouKind::Check));

        self::assertSame('Take a quick look at this new ticket.', $this->nextStepOf($pageTurn, self::member(GuardFixtures::MIA))->sentence);
        self::assertSame('Nothing for you now. Mia’s turn.', $this->nextStepOf($pageTurn, self::member(GuardFixtures::OMAR))->sentence);
    }

    #[Test]
    public function theViewerWhoseTurnItIsReadsTheVerbSentenceAndGetsTheVerbs(): void
    {
        $testing = self::pageTurn(GuardFixtures::ticket('test', 'check', assignee: GuardFixtures::OMAR, tester: GuardFixtures::MIA), new Turn(new UserId(GuardFixtures::MIA), null, NeedsYouKind::Test));
        $review = self::pageTurn(GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::OMAR, reviewer: GuardFixtures::LENA), new Turn(new UserId(GuardFixtures::LENA), null, NeedsYouKind::Review));

        $miaTests = $this->nextStepOf($testing, self::member(GuardFixtures::MIA));

        self::assertSame('Test it.', $miaTests->sentence);
        self::assertSame(['pass_it', 'needs_changes'], self::verbsOf($miaTests));
        self::assertNull($miaTests->ruleNote);
        self::assertSame('Review Omar\'s code.', $this->nextStepOf($review, self::lead(GuardFixtures::LENA))->sentence);
    }

    #[Test]
    public function aManagerWithoutATeamRoleReadsTheNowSentenceAndTheManagersNote(): void
    {
        $pageTurn = self::pageTurn(GuardFixtures::ticket('test', 'check', assignee: GuardFixtures::OMAR, tester: GuardFixtures::MIA), new Turn(new UserId(GuardFixtures::MIA), null, NeedsYouKind::Test));

        $nextStep = $this->nextStepOf($pageTurn, self::manager(), mayMove: false);

        self::assertSame('Nothing for you. Mia tests it.', $nextStep->sentence);
        self::assertSame([], $nextStep->transitions);
        self::assertSame([], $nextStep->groups);
        self::assertSame(self::MANAGERS_NOTE, $nextStep->ruleNote);
    }

    #[Test]
    public function aViewerOfTheTeamIsNoMoverEvenWhenAllowedToMove(): void
    {
        $pageTurn = self::pageTurn(GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR), new Turn(new UserId(GuardFixtures::OMAR), null, NeedsYouKind::Build));
        $viewer = new ActorContext(new UserId(GuardFixtures::VIEWER), new OrganizationId(self::ORGANIZATION), new Roles(false, false, [], [], [self::teamId()]));

        $nextStep = $this->nextStepOf($pageTurn, $viewer);

        self::assertSame('Nothing for you. Omar builds it.', $nextStep->sentence);
        self::assertSame([], $nextStep->transitions);
        self::assertSame(self::MANAGERS_NOTE, $nextStep->ruleNote);
        self::assertEquals([new ActorInTeam(new UserId(GuardFixtures::VIEWER))], $this->policy->askedActors);
    }

    #[Test]
    public function theLeadWhoMayNotMoveGetsNoVerbsNoMoveListAndNoTeamRole(): void
    {
        $pageTurn = self::pageTurn(GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR), new Turn(new UserId(GuardFixtures::OMAR), null, NeedsYouKind::Build));

        $mayMove = $this->nextStepOf($pageTurn, self::lead(GuardFixtures::LENA));
        $this->policy->askedActors = [];
        $mayNotMove = $this->nextStepOf($pageTurn, self::lead(GuardFixtures::LENA), mayMove: false);

        self::assertNotSame([], $mayMove->transitions);
        self::assertNotSame([], $mayMove->groups);
        self::assertSame('Nothing for you now. Omar’s turn.', $mayMove->sentence);
        self::assertSame([], $mayNotMove->transitions);
        self::assertSame([], $mayNotMove->groups);
        self::assertSame(self::MANAGERS_NOTE, $mayNotMove->ruleNote);
        self::assertSame('Nothing for you. Omar builds it.', $mayNotMove->sentence);
        self::assertEquals([new ActorInTeam(new UserId(GuardFixtures::LENA))], $this->policy->askedActors);
    }

    #[Test]
    public function onlyTheLeadGetsTheMoveListWithTheCurrentStepMarked(): void
    {
        $pageTurn = self::pageTurn(GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR), new Turn(new UserId(GuardFixtures::OMAR), null, NeedsYouKind::Build));

        $lena = $this->nextStepOf($pageTurn, self::lead(GuardFixtures::LENA));
        $omar = new NextStepChoices($this->policy, new FixedStepQueries([]), new PlainVerbFor(), self::nowNext())->of($pageTurn, self::member(GuardFixtures::OMAR), true);

        self::assertSame(['wait', 'check', 'doing', 'todo', 'done'], array_map(static fn (MoveChoiceGroup $group): string => $group->groupCode, $lena->groups));
        $currentSteps = array_merge(...array_map(
            static fn (MoveChoiceGroup $group): array => array_values(array_filter($group->choices, static fn (MoveChoice $choice): bool => $choice->isCurrent)),
            $lena->groups,
        ));
        self::assertSame([GuardFixtures::ticketStepId()], array_map(static fn (MoveChoice $choice): string => $choice->step->id(), $currentSteps));
        self::assertSame([], $omar->groups);
        self::assertSame(['send_to_code_review'], self::verbsOf($omar));
        self::assertSame('Build it.', $omar->sentence);
    }

    #[Test]
    public function aTurnHeldByALabelIsNamedByTheLabel(): void
    {
        $pageTurn = self::pageTurn(GuardFixtures::ticket('input', 'wait', assignee: GuardFixtures::OMAR, waitingOnLabel: 'Customer'), new Turn(null, 'Customer', NeedsYouKind::Input));

        self::assertSame('Nothing for you now. Customer’s turn.', $this->nextStepOf($pageTurn, self::member(GuardFixtures::OMAR))->sentence);
    }

    #[Test]
    public function aTurnOfSomeoneWithoutANameReadsAnUnknownPerson(): void
    {
        $pageTurn = self::pageTurn(GuardFixtures::ticket('test', 'check', assignee: GuardFixtures::OMAR, tester: GuardFixtures::RAJ), new Turn(new UserId(GuardFixtures::RAJ), null, NeedsYouKind::Test));

        self::assertSame('Nothing for you now. An unknown person’s turn.', $this->nextStepOf($pageTurn, self::member(GuardFixtures::OMAR))->sentence);
    }

    #[Test]
    public function theRuleNoteNamesTheAskedPersonByFirstName(): void
    {
        $pageTurn = self::pageTurn(GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR, asked: GuardFixtures::MIA), new Turn(new UserId(GuardFixtures::MIA), null, NeedsYouKind::Answer));

        $nextStep = $this->nextStepOf($pageTurn, self::member(GuardFixtures::OMAR));

        self::assertSame('Paused until Mia answers the question below.', $nextStep->ruleNote);
        self::assertSame([], $nextStep->transitions);
    }

    #[Test]
    public function theRuleNoteNamesAnAskedPersonWithoutANameAsAnUnknownPersonAndTheLeadGetsNoMoveList(): void
    {
        $pageTurn = self::pageTurn(GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR, asked: GuardFixtures::RAJ), new Turn(new UserId(GuardFixtures::RAJ), null, NeedsYouKind::Answer));

        $nextStep = $this->nextStepOf($pageTurn, self::lead(GuardFixtures::LENA));

        self::assertSame('Paused until an unknown person answers the question below.', $nextStep->ruleNote);
        self::assertSame([], $nextStep->groups);
    }

    #[Test]
    public function eachMoverGetsTheirJobInTheTeamAndALeadWhoNeitherDevelopsNorTestsGetsOther(): void
    {
        $team = new TeamFacts(new UserId(GuardFixtures::LENA), 'Lena', [new TeamMemberLoad(new UserId(GuardFixtures::OMAR))], [new TeamMemberLoad(new UserId(GuardFixtures::MIA))]);
        $pageTurn = self::pageTurn(GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR), new Turn(new UserId(GuardFixtures::OMAR), null, NeedsYouKind::Build), $team);

        $this->nextStepOf($pageTurn, self::lead(GuardFixtures::LENA));
        $this->nextStepOf($pageTurn, self::member(GuardFixtures::OMAR));
        $this->nextStepOf($pageTurn, self::member(GuardFixtures::MIA));

        self::assertEquals([
            new ActorInTeam(new UserId(GuardFixtures::LENA), 'lead', 'other'),
            new ActorInTeam(new UserId(GuardFixtures::LENA), 'lead', 'other'),
            new ActorInTeam(new UserId(GuardFixtures::OMAR), 'member', 'developer'),
            new ActorInTeam(new UserId(GuardFixtures::MIA), 'member', 'tester'),
        ], $this->policy->askedActors);
    }

    private function nextStepOf(PageTurn $pageTurn, ActorContext $viewer, bool $mayMove = true): NextStep
    {
        return new NextStepChoices($this->policy, new FixedStepQueries([], GuardFixtures::steps()), new PlainVerbFor(), self::nowNext())->of($pageTurn, $viewer, $mayMove);
    }

    private static function pageTurn(TicketState $ticket, Turn $turn, ?TeamFacts $team = null): PageTurn
    {
        $team ??= GuardFixtures::team();

        return new PageTurn(self::teamId(), $ticket, $team, $turn, new NowNextContext('In progress', self::NAMES, $team, true), GuardFixtures::rules());
    }

    private static function teamId(): TeamId
    {
        return GuardFixtures::ticket('todo', 'todo')->teamId ?? throw new \LogicException('The fixture ticket has a team.');
    }

    private static function nowNext(): PlainNowNext
    {
        return new PlainNowNext(new ReviewerChoice(), new TesterChoice());
    }

    private static function lead(string $userId): ActorContext
    {
        return new ActorContext(new UserId($userId), new OrganizationId(self::ORGANIZATION), new Roles(false, false, [self::teamId()], [], []));
    }

    private static function member(string $userId): ActorContext
    {
        return new ActorContext(new UserId($userId), new OrganizationId(self::ORGANIZATION), new Roles(false, false, [], [self::teamId()], []));
    }

    private static function manager(): ActorContext
    {
        return new ActorContext(new UserId(GuardFixtures::RAJ), new OrganizationId(self::ORGANIZATION), new Roles(false, true, [], [], []));
    }

    /**
     * @return list<string>
     */
    private static function verbsOf(NextStep $nextStep): array
    {
        return array_map(static fn (TransitionView $transition): string => $transition->verb, $nextStep->transitions);
    }
}

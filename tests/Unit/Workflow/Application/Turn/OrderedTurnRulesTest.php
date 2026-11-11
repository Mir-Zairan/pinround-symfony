<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Turn;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Application\Turn\OrderedTurnRules;
use App\Workflow\Application\Turn\ReviewerChoice;
use App\Workflow\Application\Turn\TesterChoice;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrderedTurnRulesTest extends TestCase
{
    private const string LENA = '0190a000-0000-7000-8000-00000000000a';
    private const string OMAR = '0190a000-0000-7000-8000-00000000000b';
    private const string MIA = '0190a000-0000-7000-8000-00000000000c';
    private const string RAJ = '0190a000-0000-7000-8000-00000000000d';
    private const string SARA = '0190a000-0000-7000-8000-00000000000e';

    #[Test]
    public function anOpenQuestionGoesToTheAskedPersonBeforeEveryOtherRule(): void
    {
        $ticket = $this->ticket('review', 'check', askedUserId: self::RAJ, assigneeId: self::OMAR, isCheckNeeded: true, checkerId: self::SARA);

        self::assertEquals(new Turn($this->id(self::RAJ), null, NeedsYouKind::Answer), $this->turn($ticket));
    }

    #[Test]
    public function anOpenQuestionBeatsDone(): void
    {
        $ticket = $this->ticket('done', 'done', askedUserId: self::RAJ);

        self::assertEquals(new Turn($this->id(self::RAJ), null, NeedsYouKind::Answer), $this->turn($ticket));
    }

    #[Test]
    public function aNewTicketNeedingACheckWithoutAnAssigneeGoesToTheChecker(): void
    {
        $ticket = $this->ticket('backlog', 'todo', isCheckNeeded: true, checkerId: self::SARA);

        self::assertEquals(new Turn($this->id(self::SARA), null, NeedsYouKind::Check), $this->turn($ticket));
    }

    #[Test]
    public function aNewTicketNeedingACheckWithAnAssigneeKeepsTheAssigneesTurn(): void
    {
        $ticket = $this->ticket('todo', 'todo', assigneeId: self::OMAR, isCheckNeeded: true, checkerId: self::SARA);

        self::assertEquals(new Turn($this->id(self::OMAR), null, NeedsYouKind::Build), $this->turn($ticket));
    }

    #[Test]
    public function aCheckNeededWithoutAnyCheckerFallsThroughToTheNextRule(): void
    {
        $ticket = $this->ticket('backlog', 'todo', isCheckNeeded: true);

        self::assertEquals(new Turn($this->id(self::LENA), null, NeedsYouKind::Build), $this->turn($ticket));
    }

    #[Test]
    public function doneIsNobodysTurnAndHasNoKind(): void
    {
        $ticket = $this->ticket('done', 'done', assigneeId: self::OMAR, reviewerId: self::LENA);

        $turn = $this->turn($ticket);

        self::assertNull($turn->userId);
        self::assertNull($turn->label);
        self::assertNull($turn->kind);
    }

    #[Test]
    public function backlogGoesToTheLead(): void
    {
        $ticket = $this->ticket('backlog', 'todo', assigneeId: self::OMAR);

        self::assertEquals(new Turn($this->id(self::LENA), null, NeedsYouKind::Build), $this->turn($ticket));
    }

    #[Test]
    public function onHoldGoesToTheLeadWhoDecides(): void
    {
        $ticket = $this->ticket('hold', 'wait', assigneeId: self::OMAR);

        self::assertEquals(new Turn($this->id(self::LENA), null, NeedsYouKind::Decide), $this->turn($ticket));
    }

    #[Test]
    public function codeReviewGoesToTheTicketsReviewer(): void
    {
        $ticket = $this->ticket('review', 'check', assigneeId: self::OMAR, reviewerId: self::RAJ);

        self::assertEquals(new Turn($this->id(self::RAJ), null, NeedsYouKind::Review), $this->turn($ticket));
    }

    #[Test]
    public function codeReviewWithoutAReviewerChoosesOneAndCarriesTheOverrideNote(): void
    {
        $ticket = $this->ticket('review', 'check', assigneeId: self::LENA);

        self::assertEquals(
            new Turn($this->id(self::LENA), null, NeedsYouKind::Review, 'Lena reviews their own code: no other developer in the team'),
            $this->turn($ticket),
        );
    }

    #[Test]
    public function codeReviewWithoutAReviewerGoesToTheLeadWhenSomeoneElseBuilt(): void
    {
        $ticket = $this->ticket('review', 'check', assigneeId: self::OMAR);

        self::assertEquals(new Turn($this->id(self::LENA), null, NeedsYouKind::Review), $this->turn($ticket));
    }

    #[Test]
    public function testingGoesToTheTicketsTester(): void
    {
        $ticket = $this->ticket('test', 'check', assigneeId: self::OMAR, testerId: self::SARA);

        self::assertEquals(new Turn($this->id(self::SARA), null, NeedsYouKind::Test), $this->turn($ticket));
    }

    #[Test]
    public function testingWithoutATesterChoosesTheTeamTester(): void
    {
        $ticket = $this->ticket('test', 'check');

        self::assertEquals(new Turn($this->id(self::MIA), null, NeedsYouKind::Test), $this->turn($ticket));
    }

    #[Test]
    public function aCustomCheckingStepGoesToTheTester(): void
    {
        $ticket = $this->ticket(null, 'check', assigneeId: self::OMAR, testerId: self::MIA);

        self::assertEquals(new Turn($this->id(self::MIA), null, NeedsYouKind::Test), $this->turn($ticket));
    }

    #[Test]
    public function testingWithNoTesterInTheTeamGoesToTheLead(): void
    {
        $ticket = $this->ticket('test', 'check');
        $team = new TeamFacts($this->id(self::LENA), 'Lena', [new TeamMemberLoad($this->id(self::OMAR))]);

        self::assertEquals(new Turn($this->id(self::LENA), null, NeedsYouKind::Test), $this->rules()->turnFor($ticket, $team));
    }

    #[Test]
    public function needsInputGoesToTheWaitingOnUserBeforeTheLabel(): void
    {
        $ticket = $this->ticket('input', 'wait', reporterId: self::RAJ, waitingOnUserId: self::SARA, waitingOnLabel: 'Customer');

        self::assertEquals(new Turn($this->id(self::SARA), null, NeedsYouKind::Input), $this->turn($ticket));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function waitingLabels(): iterable
    {
        yield 'customer' => ['Customer'];
        yield 'product owner' => ['Product owner'];
        yield 'technical owner' => ['Technical owner'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('waitingLabels')]
    public function needsInputWaitingOnALabelHasNoUserAndTheLabel(string $label): void
    {
        $ticket = $this->ticket('input', 'wait', reporterId: self::RAJ, waitingOnLabel: $label);

        self::assertEquals(new Turn(null, $label, NeedsYouKind::Input), $this->turn($ticket));
    }

    #[Test]
    public function needsInputWaitingOnNoOneGoesToTheReporter(): void
    {
        $ticket = $this->ticket('input', 'wait', reporterId: self::RAJ);

        self::assertEquals(new Turn($this->id(self::RAJ), null, NeedsYouKind::Input), $this->turn($ticket));
    }

    #[Test]
    public function needsInputWithNoOneToWaitOnGoesToTheLead(): void
    {
        self::assertEquals(new Turn($this->id(self::LENA), null, NeedsYouKind::Input), $this->turn($this->ticket('input', 'wait')));
    }

    #[Test]
    public function anyOtherStepGoesToTheAssignee(): void
    {
        $ticket = $this->ticket('progress', 'doing', assigneeId: self::OMAR);

        self::assertEquals(new Turn($this->id(self::OMAR), null, NeedsYouKind::Build), $this->turn($ticket));
    }

    #[Test]
    public function anyOtherStepWithoutAnAssigneeGoesToTheLead(): void
    {
        self::assertEquals(new Turn($this->id(self::LENA), null, NeedsYouKind::Build), $this->turn($this->ticket('progress', 'doing')));
    }

    #[Test]
    public function toDoWithoutAnAssigneeGoesToTheLeadWhoAssigns(): void
    {
        self::assertEquals(new Turn($this->id(self::LENA), null, NeedsYouKind::Assign), $this->turn($this->ticket('todo', 'todo')));
    }

    #[Test]
    public function aCustomStepWithoutAnAssigneeIsBuildNotAssign(): void
    {
        self::assertEquals(new Turn($this->id(self::LENA), null, NeedsYouKind::Build), $this->turn($this->ticket(null, 'todo')));
    }

    #[Test]
    public function anUnknownCoreCodeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->turn($this->ticket('shipped', 'done'));
    }

    private function turn(TicketState $ticket): Turn
    {
        return $this->rules()->turnFor($ticket, $this->team());
    }

    private function rules(): OrderedTurnRules
    {
        return new OrderedTurnRules(new ReviewerChoice(), new TesterChoice());
    }

    private function team(): TeamFacts
    {
        return new TeamFacts($this->id(self::LENA), 'Lena', [], [new TeamMemberLoad($this->id(self::MIA))]);
    }

    private function id(string $value): UserId
    {
        return new UserId($value);
    }

    private function ticket(
        ?string $coreCode,
        string $groupCode,
        ?string $askedUserId = null,
        ?string $assigneeId = null,
        ?string $reviewerId = null,
        ?string $testerId = null,
        ?string $reporterId = null,
        ?string $waitingOnUserId = null,
        ?string $waitingOnLabel = null,
        bool $isCheckNeeded = false,
        ?string $checkerId = null,
    ): TicketState {
        return new TicketState(
            stepId: '0190a000-0000-7000-8000-0000000000f3',
            coreCode: $coreCode,
            groupCode: $groupCode,
            teamId: new TeamId('0190a000-0000-7000-8000-0000000000f2'),
            assigneeId: $this->maybeId($assigneeId),
            reviewerId: $this->maybeId($reviewerId),
            testerId: $this->maybeId($testerId),
            askedUserId: $this->maybeId($askedUserId),
            reporterId: $this->maybeId($reporterId),
            waitingOnUserId: $this->maybeId($waitingOnUserId),
            waitingOnLabel: $waitingOnLabel,
            isCheckNeeded: $isCheckNeeded,
            checkerId: $this->maybeId($checkerId),
        );
    }

    private function maybeId(?string $value): ?UserId
    {
        return null === $value ? null : new UserId($value);
    }
}

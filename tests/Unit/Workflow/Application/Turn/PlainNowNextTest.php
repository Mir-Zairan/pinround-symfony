<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Turn;

use App\Tests\Unit\Workflow\Application\Turn\NowNextFixtures as F;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TeamMemberLoad;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Application\Turn\PlainNowNext;
use App\Workflow\Application\Turn\ReviewerChoice;
use App\Workflow\Application\Turn\TesterChoice;
use App\Workflow\Domain\CoreStep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlainNowNextTest extends TestCase
{
    /**
     * @return iterable<string, array{TicketState, Turn, NowNextContext, string}>
     */
    public static function nowSentences(): iterable
    {
        $lena = F::user(F::LENA);
        $omar = F::user(F::OMAR);
        $mia = F::user(F::MIA);
        $ben = F::user(F::BEN);

        yield 'open question' => [F::ticket('progress', 'doing', ['askedUserId' => $mia]), new Turn($mia, null, NeedsYouKind::Answer), F::context(asker: F::LENA), 'Mia answers Lena’s question'];
        yield 'new ticket check' => [F::ticket('backlog', 'todo', ['isCheckNeeded' => true, 'checkerId' => $lena]), new Turn($lena, null, NeedsYouKind::Check), F::context(), 'Lena takes a quick look at the new ticket'];
        yield 'done' => [F::ticket('done', 'done'), Turn::nobody(), F::context(), 'Done. Nobody needs to act.'];
        yield 'backlog' => [F::ticket('backlog', 'todo'), new Turn($lena, null, NeedsYouKind::Build), F::context(), 'Lena plans it'];
        yield 'to do, assigned' => [F::ticket('todo', 'todo', ['assigneeId' => $omar]), new Turn($omar, null, NeedsYouKind::Build), F::context(), 'Omar starts building'];
        yield 'to do, not assigned' => [F::ticket('todo', 'todo'), new Turn($lena, null, NeedsYouKind::Assign), F::context(), 'Lena picks a developer'];
        yield 'in progress' => [F::ticket('progress', 'doing', ['assigneeId' => $omar]), new Turn($omar, null, NeedsYouKind::Build), F::context(), 'Omar builds it'];
        yield 'code review' => [F::ticket('review', 'check'), new Turn($lena, null, NeedsYouKind::Review), F::context(), 'Lena reviews the code'];
        yield 'testing' => [F::ticket('test', 'check'), new Turn($mia, null, NeedsYouKind::Test), F::context(), 'Mia tests it'];
        yield 'needs input from a person' => [F::ticket('input', 'wait', ['waitingOnUserId' => $ben]), new Turn($ben, null, NeedsYouKind::Input), F::context(), 'Waiting for input from Ben'];
        yield 'needs input from a label' => [F::ticket('input', 'wait', ['waitingOnLabel' => 'Customer']), new Turn(null, 'Customer', NeedsYouKind::Input), F::context(), 'Waiting for input from Customer'];
        yield 'on hold' => [F::ticket('hold', 'wait'), new Turn($lena, null, NeedsYouKind::Decide), F::context(), 'Lena decides if it goes on'];
        yield 'custom step' => [F::ticket(null, 'check'), new Turn($mia, null, NeedsYouKind::Test), F::context(), 'Design check · Mia'];
        yield 'viewer builds' => [F::ticket('progress', 'doing', ['assigneeId' => $omar]), new Turn($omar, null, NeedsYouKind::Build), F::context(viewer: F::OMAR), 'You build it'];
        yield 'viewer answers' => [F::ticket('progress', 'doing', ['askedUserId' => $mia]), new Turn($mia, null, NeedsYouKind::Answer), F::context(viewer: F::MIA, asker: F::LENA), 'You answer Lena’s question'];
        yield 'viewer asked the question' => [F::ticket('progress', 'doing', ['askedUserId' => $mia]), new Turn($mia, null, NeedsYouKind::Answer), F::context(viewer: F::LENA, asker: F::LENA), 'Mia answers your question'];
        yield 'viewer checks' => [F::ticket('backlog', 'todo', ['isCheckNeeded' => true, 'checkerId' => $lena]), new Turn($lena, null, NeedsYouKind::Check), F::context(viewer: F::LENA), 'You take a quick look at the new ticket'];
        yield 'viewer is waited for' => [F::ticket('input', 'wait', ['waitingOnUserId' => $ben]), new Turn($ben, null, NeedsYouKind::Input), F::context(viewer: F::BEN), 'Waiting for input from you'];
        yield 'viewer in custom step' => [F::ticket(null, 'check'), new Turn($mia, null, NeedsYouKind::Test), F::context(viewer: F::MIA), 'Design check · you'];
        yield 'someone else is the viewer' => [F::ticket('test', 'check'), new Turn($mia, null, NeedsYouKind::Test), F::context(viewer: F::OMAR), 'Mia tests it'];
    }

    #[Test]
    #[DataProvider('nowSentences')]
    public function nowIsTheDocumentedSentence(TicketState $ticket, Turn $turn, NowNextContext $context, string $expected): void
    {
        self::assertSame($expected, self::nowNext()->now($ticket, $turn, $context));
    }

    /**
     * @return iterable<string, array{TicketState, Turn, NowNextContext, string|null}>
     */
    public static function nextSentences(): iterable
    {
        $lena = F::user(F::LENA);
        $omar = F::user(F::OMAR);
        $mia = F::user(F::MIA);
        $ben = F::user(F::BEN);
        $raj = F::user('0190a000-0000-7000-8000-00000000000d');
        $leadOnly = new TeamFacts($lena, 'Lena', [new TeamMemberLoad($lena)]);
        $leadAndOmar = new TeamFacts($lena, 'Lena', [new TeamMemberLoad($lena), new TeamMemberLoad($omar)], [new TeamMemberLoad($mia)]);

        yield 'open question' => [F::ticket('progress', 'doing', ['askedUserId' => $mia]), new Turn($mia, null, NeedsYouKind::Answer), F::context(stepName: 'In progress'), 'it goes back to In progress'];
        yield 'new ticket with a pending assignee' => [F::ticket('backlog', 'todo', ['isCheckNeeded' => true, 'checkerId' => $lena, 'pendingAssigneeId' => $omar]), new Turn($lena, null, NeedsYouKind::Check), F::context(), 'Omar gets it'];
        yield 'new ticket without one' => [F::ticket('backlog', 'todo', ['isCheckNeeded' => true, 'checkerId' => $lena]), new Turn($lena, null, NeedsYouKind::Check), F::context(), 'it is planned like any ticket'];
        yield 'done' => [F::ticket('done', 'done'), Turn::nobody(), F::context(), null];
        yield 'backlog names the people the team would pick' => [F::ticket('backlog', 'todo', ['assigneeId' => $omar]), new Turn($lena, null, NeedsYouKind::Build), F::context(), 'Omar builds it, Lena reviews, Mia tests'];
        yield 'backlog with a stored reviewer and tester' => [F::ticket('backlog', 'todo', ['assigneeId' => $omar, 'reviewerId' => $ben, 'testerId' => $ben]), new Turn($lena, null, NeedsYouKind::Build), F::context(), 'Omar builds it, Ben reviews, Ben tests'];
        yield 'to do, nobody assigned' => [F::ticket('todo', 'todo'), new Turn($lena, null, NeedsYouKind::Assign), F::context(), 'a developer builds it, Lena reviews, Mia tests'];
        yield 'to do, the lead builds, so another developer reviews' => [F::ticket('todo', 'todo', ['assigneeId' => $lena]), new Turn($lena, null, NeedsYouKind::Build), F::context(team: $leadAndOmar), 'Lena builds it, Omar reviews, Mia tests'];
        yield 'to do, review rule off and no reviewer' => [F::ticket('todo', 'todo', ['assigneeId' => $omar]), new Turn($omar, null, NeedsYouKind::Build), F::context(isReviewBeforeTest: false), 'Omar builds it, Mia tests'];
        yield 'to do, review rule off but a reviewer is stored' => [F::ticket('todo', 'todo', ['assigneeId' => $omar, 'reviewerId' => $ben]), new Turn($omar, null, NeedsYouKind::Build), F::context(isReviewBeforeTest: false), 'Omar builds it, Ben reviews, Mia tests'];
        yield 'in progress' => [F::ticket('progress', 'doing', ['assigneeId' => $omar]), new Turn($omar, null, NeedsYouKind::Build), F::context(), 'Lena reviews the code, then Mia tests it'];
        yield 'in progress, the lead builds with no other developer' => [F::ticket('progress', 'doing', ['assigneeId' => $lena]), new Turn($lena, null, NeedsYouKind::Build), F::context(team: $leadOnly), 'Lena reviews the code, then Lena tests it'];
        yield 'in progress, a team without a tester sends testing to the lead' => [F::ticket('progress', 'doing', ['assigneeId' => $omar]), new Turn($omar, null, NeedsYouKind::Build), F::context(team: new TeamFacts($lena, 'Lena', [new TeamMemberLoad($omar)])), 'Lena reviews the code, then Lena tests it'];
        yield 'in progress, rule off' => [F::ticket('progress', 'doing', ['assigneeId' => $omar]), new Turn($omar, null, NeedsYouKind::Build), F::context(isReviewBeforeTest: false), 'Mia tests it'];
        yield 'in progress, rule off with a stored reviewer' => [F::ticket('progress', 'doing', ['assigneeId' => $omar, 'reviewerId' => $ben]), new Turn($omar, null, NeedsYouKind::Build), F::context(isReviewBeforeTest: false), 'Mia tests it'];
        yield 'code review' => [F::ticket('review', 'check', ['assigneeId' => $omar]), new Turn($lena, null, NeedsYouKind::Review), F::context(), 'Mia tests it'];
        yield 'code review with a stored tester' => [F::ticket('review', 'check', ['testerId' => $ben]), new Turn($lena, null, NeedsYouKind::Review), F::context(), 'Ben tests it'];
        yield 'testing' => [F::ticket('test', 'check'), new Turn($mia, null, NeedsYouKind::Test), F::context(), 'Done'];
        yield 'needs input' => [F::ticket('input', 'wait'), new Turn(null, 'Customer', NeedsYouKind::Input), F::context(), 'it goes back to work'];
        yield 'on hold' => [F::ticket('hold', 'wait'), new Turn($lena, null, NeedsYouKind::Decide), F::context(), 'it goes back to work'];
        yield 'custom step' => [F::ticket(null, 'check'), new Turn($mia, null, NeedsYouKind::Test), F::context(), null];
        yield 'viewer tests next' => [F::ticket('review', 'check'), new Turn($lena, null, NeedsYouKind::Review), F::context(viewer: F::MIA), 'you test it'];
        yield 'viewer builds and reviews' => [F::ticket('backlog', 'todo', ['assigneeId' => $omar]), new Turn($lena, null, NeedsYouKind::Build), F::context(viewer: F::OMAR), 'you build it, Lena reviews, Mia tests'];
        yield 'viewer is the pending assignee' => [F::ticket('backlog', 'todo', ['isCheckNeeded' => true, 'checkerId' => $lena, 'pendingAssigneeId' => $omar]), new Turn($lena, null, NeedsYouKind::Check), F::context(viewer: F::OMAR), 'you get it'];
        yield 'a stored reviewer and tester without a name' => [F::ticket('progress', 'doing', ['assigneeId' => $omar, 'reviewerId' => $raj, 'testerId' => $raj]), new Turn($omar, null, NeedsYouKind::Build), F::context(), 'a reviewer reviews the code, then a tester tests it'];
        yield 'a stored reviewer and tester whose name is only spaces' => [F::ticket('progress', 'doing', ['assigneeId' => $omar, 'reviewerId' => $ben, 'testerId' => $ben]), new Turn($omar, null, NeedsYouKind::Build), F::context(names: [F::BEN => '   ']), 'a reviewer reviews the code, then a tester tests it'];
        yield 'a full name is cut to the first name' => [F::ticket('review', 'check', ['testerId' => $lena]), new Turn($ben, null, NeedsYouKind::Review), F::context(), 'Lena tests it'];
    }

    #[Test]
    #[DataProvider('nextSentences')]
    public function nextIsTheDocumentedSentence(TicketState $ticket, Turn $turn, NowNextContext $context, ?string $expected): void
    {
        self::assertSame($expected, self::nowNext()->next($ticket, $turn, $context));
    }

    #[Test]
    public function noSentenceContainsAStepCode(): void
    {
        $nowNext = self::nowNext();
        $lena = F::user(F::LENA);
        $codes = '/\b(backlog|todo|progress|hold|in_progress|review|test)\b|0190a000-0000-7000-8000-0000000000aa/i';
        $seen = 0;

        $steps = array_map(static fn (CoreStep $step): string => $step->value, CoreStep::cases());
        foreach ([...$steps, null] as $coreCode) {
            foreach (NeedsYouKind::cases() as $kind) {
                $ticket = F::ticket($coreCode, 'doing', ['assigneeId' => $lena, 'waitingOnLabel' => 'Customer']);
                $context = F::context(stepName: 'Design check');
                $turn = new Turn($lena, null, $kind);

                foreach ([$nowNext->now($ticket, $turn, $context), $nowNext->next($ticket, $turn, $context)] as $sentence) {
                    if (null === $sentence) {
                        continue;
                    }

                    ++$seen;
                    self::assertDoesNotMatchRegularExpression($codes, $sentence, 'for step '.($coreCode ?? 'custom'));
                }
            }
        }

        self::assertGreaterThan(40, $seen);
    }

    #[Test]
    public function nowNeedsNoTeamAndNamesTheTurnPersonAndTheViewer(): void
    {
        $omar = F::user(F::OMAR);
        $ticket = F::ticket('progress', 'doing', ['assigneeId' => $omar]);
        $names = [F::LENA => 'Lena Hart', F::OMAR => 'Omar'];

        self::assertSame('Omar builds it', self::nowNext()->now($ticket, new Turn($omar, null, NeedsYouKind::Build), NowNextContext::forNowOnly('In progress', $names, F::user(F::LENA))));
        self::assertSame('You build it', self::nowNext()->now($ticket, new Turn($omar, null, NeedsYouKind::Build), NowNextContext::forNowOnly('In progress', $names, $omar)));
    }

    #[Test]
    public function nextRefusesAContextForNowOnlyRatherThanNameAMadeUpReviewer(): void
    {
        $omar = F::user(F::OMAR);
        $context = NowNextContext::forNowOnly('In progress', [F::LENA => 'Lena Hart', F::OMAR => 'Omar'], F::user(F::LENA));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The Next sentence needs the team');

        self::nowNext()->next(F::ticket('progress', 'doing', ['assigneeId' => $omar]), new Turn($omar, null, NeedsYouKind::Build), $context);
    }

    #[Test]
    public function nextForAnOpenQuestionNeedsNoTeam(): void
    {
        $mia = F::user(F::MIA);
        $context = NowNextContext::forNowOnly('In progress', [F::MIA => 'Mia'], askerId: F::user(F::LENA));

        self::assertSame('it goes back to In progress', self::nowNext()->next(F::ticket('progress', 'doing', ['askedUserId' => $mia]), new Turn($mia, null, NeedsYouKind::Answer), $context));
    }

    #[Test]
    public function anUnknownCoreCodeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::nowNext()->now(F::ticket('qa', 'check'), Turn::nobody(), F::context());
    }

    private static function nowNext(): PlainNowNext
    {
        return new PlainNowNext(new ReviewerChoice(), new TesterChoice());
    }
}

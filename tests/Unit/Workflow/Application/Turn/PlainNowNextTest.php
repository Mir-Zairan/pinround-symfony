<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Turn;

use App\Tests\Unit\Workflow\Application\Turn\NowNextFixtures as F;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Application\Turn\PlainNowNext;
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

        yield 'open question' => [F::ticket('progress', 'doing', ['askedUserId' => $mia]), new Turn($mia, null, NeedsYouKind::Answer), F::context(asker: F::LENA), "Mia answers Lena's question"];
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
        yield 'first name only' => [F::ticket('backlog', 'todo'), new Turn($lena, null, NeedsYouKind::Build), F::context(), 'Lena plans it'];
        yield 'viewer builds' => [F::ticket('progress', 'doing', ['assigneeId' => $omar]), new Turn($omar, null, NeedsYouKind::Build), F::context(viewer: F::OMAR), 'You build it'];
        yield 'viewer answers' => [F::ticket('progress', 'doing', ['askedUserId' => $mia]), new Turn($mia, null, NeedsYouKind::Answer), F::context(viewer: F::MIA, asker: F::LENA), "You answer Lena's question"];
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
        self::assertSame($expected, new PlainNowNext()->now($ticket, $turn, $context));
    }

    /**
     * @return iterable<string, array{TicketState, Turn, NowNextContext, string|null}>
     */
    public static function nextSentences(): iterable
    {
        $lena = F::user(F::LENA);
        $omar = F::user(F::OMAR);
        $mia = F::user(F::MIA);

        yield 'open question' => [F::ticket('progress', 'doing', ['askedUserId' => $mia]), new Turn($mia, null, NeedsYouKind::Answer), F::context(stepName: 'In progress'), 'it goes back to In progress'];
        yield 'new ticket with a pending assignee' => [F::ticket('backlog', 'todo', ['isCheckNeeded' => true, 'checkerId' => $lena, 'pendingAssigneeId' => $omar]), new Turn($lena, null, NeedsYouKind::Check), F::context(), 'Omar gets it'];
        yield 'new ticket without one' => [F::ticket('backlog', 'todo', ['isCheckNeeded' => true, 'checkerId' => $lena]), new Turn($lena, null, NeedsYouKind::Check), F::context(), 'it is planned like any ticket'];
        yield 'done' => [F::ticket('done', 'done'), Turn::nobody(), F::context(), null];
        yield 'backlog, all named' => [F::ticket('backlog', 'todo', ['assigneeId' => $omar, 'reviewerId' => $lena, 'testerId' => $mia]), new Turn($lena, null, NeedsYouKind::Build), F::context(), 'Omar builds it, Lena reviews, Mia tests'];
        yield 'to do, nobody named' => [F::ticket('todo', 'todo'), new Turn($lena, null, NeedsYouKind::Assign), F::context(), 'A developer builds it, a reviewer reviews, a tester tests'];
        yield 'to do, review rule off and no reviewer' => [F::ticket('todo', 'todo', ['assigneeId' => $omar, 'testerId' => $mia]), new Turn($omar, null, NeedsYouKind::Build), F::context(isReviewBeforeTest: false), 'Omar builds it, Mia tests'];
        yield 'to do, review rule off but a reviewer' => [F::ticket('todo', 'todo', ['assigneeId' => $omar, 'reviewerId' => $lena, 'testerId' => $mia]), new Turn($omar, null, NeedsYouKind::Build), F::context(isReviewBeforeTest: false), 'Omar builds it, Lena reviews, Mia tests'];
        yield 'in progress' => [F::ticket('progress', 'doing', ['reviewerId' => $lena, 'testerId' => $mia]), new Turn($omar, null, NeedsYouKind::Build), F::context(), 'Lena reviews the code, then Mia tests it'];
        yield 'in progress, rule off' => [F::ticket('progress', 'doing', ['testerId' => $mia]), new Turn($omar, null, NeedsYouKind::Build), F::context(isReviewBeforeTest: false), 'Mia tests it'];
        yield 'code review' => [F::ticket('review', 'check', ['testerId' => $mia]), new Turn($lena, null, NeedsYouKind::Review), F::context(), 'Mia tests it'];
        yield 'testing' => [F::ticket('test', 'check'), new Turn($mia, null, NeedsYouKind::Test), F::context(), 'Done'];
        yield 'needs input' => [F::ticket('input', 'wait'), new Turn(null, 'Customer', NeedsYouKind::Input), F::context(), 'it goes back to work'];
        yield 'on hold' => [F::ticket('hold', 'wait'), new Turn($lena, null, NeedsYouKind::Decide), F::context(), 'it goes back to work'];
        yield 'custom step' => [F::ticket(null, 'check'), new Turn($mia, null, NeedsYouKind::Test), F::context(), null];
        yield 'viewer tests next' => [F::ticket('review', 'check', ['testerId' => $mia]), new Turn($lena, null, NeedsYouKind::Review), F::context(viewer: F::MIA), 'You test it'];
        yield 'viewer builds and reviews' => [F::ticket('backlog', 'todo', ['assigneeId' => $omar, 'reviewerId' => $lena, 'testerId' => $mia]), new Turn($lena, null, NeedsYouKind::Build), F::context(viewer: F::OMAR), 'You build it, Lena reviews, Mia tests'];
        yield 'viewer is the pending assignee' => [F::ticket('backlog', 'todo', ['isCheckNeeded' => true, 'checkerId' => $lena, 'pendingAssigneeId' => $omar]), new Turn($lena, null, NeedsYouKind::Check), F::context(viewer: F::OMAR), 'You get it'];
    }

    #[Test]
    #[DataProvider('nextSentences')]
    public function nextIsTheDocumentedSentence(TicketState $ticket, Turn $turn, NowNextContext $context, ?string $expected): void
    {
        self::assertSame($expected, new PlainNowNext()->next($ticket, $turn, $context));
    }

    #[Test]
    public function noSentenceContainsAStepCode(): void
    {
        $nowNext = new PlainNowNext();
        $lena = F::user(F::LENA);
        $codes = '/\b(backlog|todo|progress|hold|in_progress)\b|0190a000-0000-7000-8000-0000000000aa/i';
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
    public function aCustomStepIsNamedByItsLabelInNow(): void
    {
        $sentence = new PlainNowNext()->now(F::ticket(null, 'check'), new Turn(F::user(F::MIA), null, NeedsYouKind::Test), F::context(stepName: 'Design check'));

        self::assertStringStartsWith('Design check', $sentence);
    }

    #[Test]
    public function anUnknownCoreCodeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PlainNowNext()->now(F::ticket('qa', 'check'), Turn::nobody(), F::context());
    }
}

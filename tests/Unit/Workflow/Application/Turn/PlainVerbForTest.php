<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Turn;

use App\Tests\Unit\Workflow\Application\Turn\NowNextFixtures as F;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Application\Turn\PlainVerbFor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlainVerbForTest extends TestCase
{
    /**
     * @return iterable<string, array{TicketState, Turn, NowNextContext, string|null}>
     */
    public static function verbs(): iterable
    {
        $lena = F::user(F::LENA);
        $omar = F::user(F::OMAR);
        $mia = F::user(F::MIA);

        yield 'test' => [F::ticket('test', 'check'), new Turn($mia, null, NeedsYouKind::Test), F::context(), 'Test it'];
        yield 'review' => [F::ticket('review', 'check', ['assigneeId' => $omar]), new Turn($lena, null, NeedsYouKind::Review), F::context(), 'Review Omar’s code'];
        yield 'review without an assignee' => [F::ticket('review', 'check'), new Turn($lena, null, NeedsYouKind::Review), F::context(), 'Review the code'];
        yield 'review your own code' => [F::ticket('review', 'check', ['assigneeId' => $lena]), new Turn($lena, null, NeedsYouKind::Review), F::context(viewer: F::LENA), 'Review your code'];
        yield 'answer' => [F::ticket('progress', 'doing', ['askedUserId' => $omar]), new Turn($omar, null, NeedsYouKind::Answer), F::context(asker: F::MIA), 'Answer Mia’s question'];
        yield 'answer without a known asker' => [F::ticket('progress', 'doing'), new Turn($omar, null, NeedsYouKind::Answer), F::context(), 'Answer the question'];
        yield 'check' => [F::ticket('backlog', 'todo'), new Turn($lena, null, NeedsYouKind::Check), F::context(), 'Check the new ticket'];
        yield 'plan' => [F::ticket('backlog', 'todo'), new Turn($lena, null, NeedsYouKind::Build), F::context(), 'Plan it'];
        yield 'pick a developer' => [F::ticket('todo', 'todo'), new Turn($lena, null, NeedsYouKind::Assign), F::context(), 'Pick a developer'];
        yield 'start building' => [F::ticket('todo', 'todo', ['assigneeId' => $omar]), new Turn($omar, null, NeedsYouKind::Build), F::context(), 'Start building'];
        yield 'start building in a custom to do step' => [F::ticket(null, 'todo', ['assigneeId' => $omar]), new Turn($omar, null, NeedsYouKind::Build), F::context(), 'Start building'];
        yield 'build' => [F::ticket('progress', 'doing', ['assigneeId' => $omar]), new Turn($omar, null, NeedsYouKind::Build), F::context(), 'Build it'];
        yield 'build in a custom doing step' => [F::ticket(null, 'doing', ['assigneeId' => $omar]), new Turn($omar, null, NeedsYouKind::Build), F::context(), 'Build it'];
        yield 'give input' => [F::ticket('input', 'wait'), new Turn($omar, null, NeedsYouKind::Input), F::context(), 'Give input'];
        yield 'decide' => [F::ticket('hold', 'wait'), new Turn($lena, null, NeedsYouKind::Decide), F::context(), 'Decide: go on or stop'];
        yield 'nobody' => [F::ticket('done', 'done'), Turn::nobody(), F::context(), null];
    }

    #[Test]
    #[DataProvider('verbs')]
    public function personGetsTheShortTask(TicketState $ticket, Turn $turn, NowNextContext $context, ?string $expected): void
    {
        self::assertSame($expected, new PlainVerbFor()->person($ticket, $turn, $context));
    }
}

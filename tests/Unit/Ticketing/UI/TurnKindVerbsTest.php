<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Tests\Unit\Workflow\Application\Turn\NowNextFixtures as F;
use App\Ticketing\UI\TurnKindVerbs;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Application\Turn\PlainVerbFor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TurnKindVerbsTest extends TestCase
{
    /**
     * The tickets PlainVerbForTest gives each kind's text with no names: no asker, no assignee, a doing step.
     *
     * @return iterable<string, array{NeedsYouKind, TicketState}>
     */
    public static function kindsWithTheirNamelessTicket(): iterable
    {
        yield 'answer' => [NeedsYouKind::Answer, F::ticket('progress', 'doing')];
        yield 'check' => [NeedsYouKind::Check, F::ticket('backlog', 'todo')];
        yield 'review' => [NeedsYouKind::Review, F::ticket('review', 'check')];
        yield 'test' => [NeedsYouKind::Test, F::ticket('test', 'check')];
        yield 'assign' => [NeedsYouKind::Assign, F::ticket('todo', 'todo')];
        yield 'input' => [NeedsYouKind::Input, F::ticket('input', 'wait')];
        yield 'decide' => [NeedsYouKind::Decide, F::ticket('hold', 'wait')];
        yield 'build' => [NeedsYouKind::Build, F::ticket('progress', 'doing', ['assigneeId' => F::user(F::OMAR)])];
    }

    #[Test]
    #[DataProvider('kindsWithTheirNamelessTicket')]
    public function eachKindReadsAsPlainVerbForWritesItWithoutNames(NeedsYouKind $kind, TicketState $ticket): void
    {
        $plainVerb = new PlainVerbFor()->person($ticket, new Turn(F::user(F::LENA), null, $kind), F::context());

        self::assertNotNull($plainVerb);
        self::assertSame($plainVerb, TurnKindVerbs::of($kind->value));
    }

    #[Test]
    public function everyKindHasItsTicketInTheComparison(): void
    {
        $comparedKinds = array_map(
            static fn (array $kindAndTicket): NeedsYouKind => $kindAndTicket[0],
            iterator_to_array(self::kindsWithTheirNamelessTicket(), false),
        );

        self::assertEqualsCanonicalizing(NeedsYouKind::cases(), $comparedKinds);
    }

    #[Test]
    public function aKindThatIsNoNeedsYouKindIsRefused(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('"Test"');

        TurnKindVerbs::of('Test');
    }
}

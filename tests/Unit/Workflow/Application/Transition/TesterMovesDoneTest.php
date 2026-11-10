<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\Workflow\Api\ActorInTeam;
use App\Workflow\Application\Transition\TesterMovesDone;
use App\Workflow\Domain\TransitionCatalogue;
use App\Workflow\Domain\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TesterMovesDoneTest extends TestCase
{
    private const string NOTE = 'Team rule: only the tester moves a ticket to Done.';

    /**
     * @return iterable<string, array{bool, ActorInTeam, string|null}> rule on, actor with their job,
     *                                                                 rendered note or null
     */
    public static function actorsPassingATicket(): iterable
    {
        yield 'on, tester' => [true, GuardFixtures::mia(), null];
        yield 'on, lead' => [true, GuardFixtures::lena(), self::NOTE];
        yield 'on, lead with the job tester' => [true, GuardFixtures::lena('tester'), null];
        yield 'on, assignee developer' => [true, GuardFixtures::omar(), self::NOTE];
        yield 'on, other job' => [true, GuardFixtures::omar('other'), self::NOTE];
        yield 'on, manager outside the team' => [true, GuardFixtures::raj(), self::NOTE];
        yield 'off, tester' => [false, GuardFixtures::mia(), null];
        yield 'off, lead' => [false, GuardFixtures::lena(), null];
        yield 'off, assignee developer' => [false, GuardFixtures::omar(), null];
    }

    #[Test]
    #[DataProvider('actorsPassingATicket')]
    public function withTheRuleOnOnlyATesterPassesATicket(bool $isRuleOn, ActorInTeam $actor, ?string $expectedNote): void
    {
        $ticket = GuardFixtures::ticket('test', 'check', assignee: GuardFixtures::OMAR, reviewer: GuardFixtures::LENA, tester: GuardFixtures::MIA);

        $note = new TesterMovesDone()->check($ticket, new TransitionCatalogue()->for(Verb::PassIt), $actor, GuardFixtures::rules(testerMovesDone: $isRuleOn));

        self::assertSame($expectedNote, $note?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function aMoveThatDoesNotGoToDoneIsNotItsBusiness(): void
    {
        $ticket = GuardFixtures::ticket('test', 'check', assignee: GuardFixtures::OMAR, tester: GuardFixtures::MIA);
        $guard = new TesterMovesDone();

        foreach (new TransitionCatalogue()->all() as $transition) {
            if (Verb::PassIt === $transition->verb) {
                continue;
            }

            self::assertNull($guard->check($ticket, $transition, GuardFixtures::lena(), GuardFixtures::rules()), $transition->label);
        }
    }
}

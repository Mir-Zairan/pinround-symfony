<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberJob;
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
     * @return iterable<string, array{bool, ActorContext, MemberJob|null, string|null}> rule on, actor, actor's job,
     *                                                                                  rendered note or null
     */
    public static function actorsPassingATicket(): iterable
    {
        yield 'on, tester' => [true, GuardFixtures::mia(), MemberJob::Tester, null];
        yield 'on, lead' => [true, GuardFixtures::lena(), MemberJob::Developer, self::NOTE];
        yield 'on, lead with the job tester' => [true, GuardFixtures::lena(), MemberJob::Tester, null];
        yield 'on, assignee developer' => [true, GuardFixtures::omar(), MemberJob::Developer, self::NOTE];
        yield 'on, other job' => [true, GuardFixtures::omar(), MemberJob::Other, self::NOTE];
        yield 'on, manager outside the team' => [true, GuardFixtures::raj(), null, self::NOTE];
        yield 'off, tester' => [false, GuardFixtures::mia(), MemberJob::Tester, null];
        yield 'off, lead' => [false, GuardFixtures::lena(), MemberJob::Developer, null];
        yield 'off, assignee developer' => [false, GuardFixtures::omar(), MemberJob::Developer, null];
    }

    #[Test]
    #[DataProvider('actorsPassingATicket')]
    public function withTheRuleOnOnlyATesterPassesATicket(bool $isRuleOn, ActorContext $actor, ?MemberJob $actorJob, ?string $expectedNote): void
    {
        $ticket = GuardFixtures::ticket('test', 'check', assignee: GuardFixtures::OMAR, reviewer: GuardFixtures::LENA, tester: GuardFixtures::MIA);

        $note = new TesterMovesDone()->check($ticket, new TransitionCatalogue()->for(Verb::PassIt), $actor, $actorJob, GuardFixtures::rules(testerMovesDone: $isRuleOn));

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

            self::assertNull($guard->check($ticket, $transition, GuardFixtures::lena(), MemberJob::Developer, GuardFixtures::rules()), $transition->label);
        }
    }
}

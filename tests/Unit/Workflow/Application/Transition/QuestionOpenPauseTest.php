<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberJob;
use App\Workflow\Application\Transition\QuestionOpenPause;
use App\Workflow\Domain\TransitionCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuestionOpenPauseTest extends TestCase
{
    /**
     * @return iterable<string, array{ActorContext, MemberJob|null}>
     */
    public static function actors(): iterable
    {
        yield 'lead' => [GuardFixtures::lena(), MemberJob::Developer];
        yield 'assignee developer' => [GuardFixtures::omar(), MemberJob::Developer];
        yield 'tester, the asked person' => [GuardFixtures::mia(), MemberJob::Tester];
        yield 'manager outside the team' => [GuardFixtures::raj(), null];
    }

    #[Test]
    #[DataProvider('actors')]
    public function whileAQuestionIsOpenEveryMoveIsPausedForEveryone(ActorContext $actor, ?MemberJob $actorJob): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR, asked: GuardFixtures::MIA);
        $guard = new QuestionOpenPause();

        foreach (new TransitionCatalogue()->all() as $transition) {
            $note = $guard->check($ticket, $transition, $actor, $actorJob, GuardFixtures::rules(false, false, false));

            self::assertSame('Paused until Mia answers the question below.', $note?->render(GuardFixtures::nameOf(...)), $transition->label);
        }
    }

    #[Test]
    #[DataProvider('actors')]
    public function withNoQuestionOpenNothingIsPaused(ActorContext $actor, ?MemberJob $actorJob): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);
        $guard = new QuestionOpenPause();

        foreach (new TransitionCatalogue()->all() as $transition) {
            self::assertNull($guard->check($ticket, $transition, $actor, $actorJob, GuardFixtures::rules()), $transition->label);
        }
    }
}

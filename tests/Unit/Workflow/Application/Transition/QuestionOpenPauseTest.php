<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\Workflow\Api\ActorInTeam;
use App\Workflow\Application\Transition\QuestionOpenPause;
use App\Workflow\Domain\TransitionCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuestionOpenPauseTest extends TestCase
{
    /**
     * @return iterable<string, array{ActorInTeam}>
     */
    public static function actors(): iterable
    {
        yield 'lead' => [GuardFixtures::lena()];
        yield 'assignee developer' => [GuardFixtures::omar()];
        yield 'tester, the asked person' => [GuardFixtures::mia()];
        yield 'manager outside the team' => [GuardFixtures::raj()];
    }

    #[Test]
    #[DataProvider('actors')]
    public function whileAQuestionIsOpenEveryMoveIsPausedForEveryone(ActorInTeam $actor): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR, asked: GuardFixtures::MIA);
        $guard = new QuestionOpenPause();

        foreach (new TransitionCatalogue()->all() as $transition) {
            $note = $guard->check($ticket, $transition, $actor, GuardFixtures::rules(false, false, false));

            self::assertSame('Paused until Mia answers the question below.', $note?->render(GuardFixtures::nameOf(...)), $transition->label);
        }
    }

    #[Test]
    #[DataProvider('actors')]
    public function withNoQuestionOpenNothingIsPaused(ActorInTeam $actor): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);
        $guard = new QuestionOpenPause();

        foreach (new TransitionCatalogue()->all() as $transition) {
            self::assertNull($guard->check($ticket, $transition, $actor, GuardFixtures::rules()), $transition->label);
        }
    }
}

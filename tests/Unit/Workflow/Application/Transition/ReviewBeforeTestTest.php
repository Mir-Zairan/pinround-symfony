<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\Workflow\Api\ActorInTeam;
use App\Workflow\Application\Transition\ReviewBeforeTest;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Mover;
use App\Workflow\Domain\Transition;
use App\Workflow\Domain\TransitionCatalogue;
use App\Workflow\Domain\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReviewBeforeTestTest extends TestCase
{
    private const string NOTE = 'Team rule: code review comes before testing.';

    /**
     * @return iterable<string, array{bool, ActorInTeam, string|null}> rule on, actor with their job,
     *                                                                 rendered note or null
     */
    public static function actorsSkippingCodeReview(): iterable
    {
        yield 'on, assignee developer' => [true, GuardFixtures::omar(), self::NOTE];
        yield 'on, lead' => [true, GuardFixtures::lena(), self::NOTE];
        yield 'on, tester' => [true, GuardFixtures::mia(), self::NOTE];
        yield 'off, assignee developer' => [false, GuardFixtures::omar(), null];
        yield 'off, lead' => [false, GuardFixtures::lena(), null];
    }

    #[Test]
    #[DataProvider('actorsSkippingCodeReview')]
    public function withTheRuleOnNobodyMovesFromInProgressStraightToTesting(bool $isRuleOn, ActorInTeam $actor, ?string $expectedNote): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);

        $note = new ReviewBeforeTest()->check($ticket, self::sendToTesting(), $actor, GuardFixtures::rules(reviewBeforeTest: $isRuleOn));

        self::assertSame($expectedNote, $note?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function onlySendToTestingOfTheCatalogueSkipsCodeReview(): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);
        $guard = new ReviewBeforeTest();

        foreach (new TransitionCatalogue()->all() as $transition) {
            $note = $guard->check($ticket, $transition, GuardFixtures::omar(), GuardFixtures::rules());

            if (Verb::SendToTesting === $transition->verb) {
                self::assertSame(self::NOTE, $note?->render(GuardFixtures::nameOf(...)));
            } else {
                self::assertNull($note, $transition->label);
            }
        }
    }

    #[Test]
    public function aMoveFromInProgressElsewhereOrIntoTestingFromElsewhereIsAllowed(): void
    {
        $ticket = GuardFixtures::ticket('progress', 'doing', assignee: GuardFixtures::OMAR);
        $guard = new ReviewBeforeTest();
        $intoHold = new Transition(Verb::SendToCodeReview, 'Put on hold', [CoreStep::Progress], CoreStep::Hold, [Mover::Lead], false);

        self::assertNull($guard->check($ticket, $intoHold, GuardFixtures::lena(), GuardFixtures::rules()));
        self::assertNull($guard->check($ticket, new TransitionCatalogue()->for(Verb::ApproveCode), GuardFixtures::lena(), GuardFixtures::rules()));
    }

    /**
     * "Send to testing" is not a verb of this slice; the row is built by hand so the rule has a move to block.
     */
    private static function sendToTesting(): Transition
    {
        return new Transition(Verb::SendToCodeReview, 'Send to testing', [CoreStep::Progress], CoreStep::Test, [Mover::Assignee], false);
    }
}

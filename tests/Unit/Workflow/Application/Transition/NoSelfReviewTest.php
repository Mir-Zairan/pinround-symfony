<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\Workflow\Api\ActorInTeam;
use App\Workflow\Application\Transition\NoSelfReview;
use App\Workflow\Domain\CoreStep;
use App\Workflow\Domain\Mover;
use App\Workflow\Domain\Transition;
use App\Workflow\Domain\TransitionCatalogue;
use App\Workflow\Domain\Verb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NoSelfReviewTest extends TestCase
{
    /**
     * Lena leads the team and is the assignee, so Omar reviews.
     *
     * @return iterable<string, array{bool, ActorInTeam, string|null}> rule on, actor with their job,
     *                                                                 rendered note or null
     */
    public static function actorsApprovingLenasCode(): iterable
    {
        yield 'on, assignee and lead' => [true, GuardFixtures::lena(), 'Team rule: nobody approves their own code. Omar reviews it.'];
        yield 'on, reviewer developer' => [true, GuardFixtures::omar(), null];
        yield 'on, tester' => [true, GuardFixtures::mia(), null];
        yield 'on, manager outside the team' => [true, GuardFixtures::raj(), null];
        yield 'off, assignee and lead' => [false, GuardFixtures::lena(), null];
        yield 'off, reviewer developer' => [false, GuardFixtures::omar(), null];
    }

    #[Test]
    #[DataProvider('actorsApprovingLenasCode')]
    public function withTheRuleOnTheAssigneeDoesNotApproveTheirOwnCode(bool $isRuleOn, ActorInTeam $actor, ?string $expectedNote): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::LENA, reviewer: GuardFixtures::OMAR, tester: GuardFixtures::MIA);

        $note = new NoSelfReview()->check($ticket, new TransitionCatalogue()->for(Verb::ApproveCode), $actor, GuardFixtures::rules(noSelfReview: $isRuleOn));

        self::assertSame($expectedNote, $note?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function anAssigneeWhoIsNotTheLeadIsBlockedTooAndTheNoteNamesTheirReviewer(): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::OMAR, reviewer: GuardFixtures::LENA);

        $note = new NoSelfReview()->check($ticket, new TransitionCatalogue()->for(Verb::ApproveCode), GuardFixtures::omar(), GuardFixtures::rules());

        self::assertSame('Team rule: nobody approves their own code. Lena reviews it.', $note?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function withNoReviewerSetTheNoteNamesNobody(): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::LENA);

        $note = new NoSelfReview()->check($ticket, new TransitionCatalogue()->for(Verb::ApproveCode), GuardFixtures::lena(), GuardFixtures::rules());

        self::assertSame('Team rule: nobody approves their own code.', $note?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function whenTheReviewerIsTheAssigneeTheFallbackLetsThemApprove(): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::LENA, reviewer: GuardFixtures::LENA);

        self::assertNull(new NoSelfReview()->check($ticket, new TransitionCatalogue()->for(Verb::ApproveCode), GuardFixtures::lena(), GuardFixtures::rules()));
    }

    #[Test]
    public function aTicketWithoutAnAssigneeHasNobodyToBlock(): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', reviewer: GuardFixtures::OMAR);

        self::assertNull(new NoSelfReview()->check($ticket, new TransitionCatalogue()->for(Verb::ApproveCode), GuardFixtures::lena(), GuardFixtures::rules()));
    }

    #[Test]
    public function onlyAMoveFromCodeReviewToTestingIsBlocked(): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::LENA, reviewer: GuardFixtures::OMAR);
        $guard = new NoSelfReview();
        $intoTestingFromElsewhere = new Transition(Verb::ApproveCode, 'Send to testing', [CoreStep::Progress], CoreStep::Test, [Mover::Assignee], false);

        self::assertNull($guard->check($ticket, new TransitionCatalogue()->for(Verb::AskForChanges), GuardFixtures::lena(), GuardFixtures::rules()));
        self::assertNull($guard->check($ticket, $intoTestingFromElsewhere, GuardFixtures::lena(), GuardFixtures::rules()));
    }
}

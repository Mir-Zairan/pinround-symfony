<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Application\Transition;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MemberJob;
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
     * @return iterable<string, array{bool, ActorContext, MemberJob|null, string|null}> rule on, actor, actor's job,
     *                                                                                  rendered note or null
     */
    public static function actorsApprovingLenasCode(): iterable
    {
        yield 'on, assignee and lead' => [true, GuardFixtures::lena(), MemberJob::Developer, 'Team rule: nobody approves their own code. Omar reviews it.'];
        yield 'on, reviewer developer' => [true, GuardFixtures::omar(), MemberJob::Developer, null];
        yield 'on, tester' => [true, GuardFixtures::mia(), MemberJob::Tester, null];
        yield 'on, manager outside the team' => [true, GuardFixtures::raj(), null, null];
        yield 'off, assignee and lead' => [false, GuardFixtures::lena(), MemberJob::Developer, null];
        yield 'off, reviewer developer' => [false, GuardFixtures::omar(), MemberJob::Developer, null];
    }

    #[Test]
    #[DataProvider('actorsApprovingLenasCode')]
    public function withTheRuleOnTheAssigneeDoesNotApproveTheirOwnCode(bool $isRuleOn, ActorContext $actor, ?MemberJob $actorJob, ?string $expectedNote): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::LENA, reviewer: GuardFixtures::OMAR, tester: GuardFixtures::MIA);

        $note = new NoSelfReview()->check($ticket, new TransitionCatalogue()->for(Verb::ApproveCode), $actor, $actorJob, GuardFixtures::rules(noSelfReview: $isRuleOn));

        self::assertSame($expectedNote, $note?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function anAssigneeWhoIsNotTheLeadIsBlockedTooAndTheNoteNamesTheirReviewer(): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::OMAR, reviewer: GuardFixtures::LENA);

        $note = new NoSelfReview()->check($ticket, new TransitionCatalogue()->for(Verb::ApproveCode), GuardFixtures::omar(), MemberJob::Developer, GuardFixtures::rules());

        self::assertSame('Team rule: nobody approves their own code. Lena reviews it.', $note?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function withNoReviewerSetTheNoteNamesNobody(): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::LENA);

        $note = new NoSelfReview()->check($ticket, new TransitionCatalogue()->for(Verb::ApproveCode), GuardFixtures::lena(), MemberJob::Developer, GuardFixtures::rules());

        self::assertSame('Team rule: nobody approves their own code.', $note?->render(GuardFixtures::nameOf(...)));
    }

    #[Test]
    public function whenTheReviewerIsTheAssigneeTheFallbackLetsThemApprove(): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::LENA, reviewer: GuardFixtures::LENA);

        self::assertNull(new NoSelfReview()->check($ticket, new TransitionCatalogue()->for(Verb::ApproveCode), GuardFixtures::lena(), MemberJob::Developer, GuardFixtures::rules()));
    }

    #[Test]
    public function aTicketWithoutAnAssigneeHasNobodyToBlock(): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', reviewer: GuardFixtures::OMAR);

        self::assertNull(new NoSelfReview()->check($ticket, new TransitionCatalogue()->for(Verb::ApproveCode), GuardFixtures::lena(), MemberJob::Developer, GuardFixtures::rules()));
    }

    #[Test]
    public function onlyAMoveFromCodeReviewToTestingIsBlocked(): void
    {
        $ticket = GuardFixtures::ticket('review', 'check', assignee: GuardFixtures::LENA, reviewer: GuardFixtures::OMAR);
        $guard = new NoSelfReview();
        $intoTestingFromElsewhere = new Transition(Verb::ApproveCode, 'Send to testing', [CoreStep::Progress], CoreStep::Test, [Mover::Assignee], false);

        self::assertNull($guard->check($ticket, new TransitionCatalogue()->for(Verb::AskForChanges), GuardFixtures::lena(), MemberJob::Developer, GuardFixtures::rules()));
        self::assertNull($guard->check($ticket, $intoTestingFromElsewhere, GuardFixtures::lena(), MemberJob::Developer, GuardFixtures::rules()));
    }
}

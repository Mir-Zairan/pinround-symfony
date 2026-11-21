<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Review\Api\ReviewRoundView;
use App\Review\Application\OpenSharedRound;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which rounds of the round page are open hand-off rounds, and that a read round answers with Round's own rule.
 */
final class OpenSharedRoundTest extends TestCase
{
    private const string OMAR = '0190a000-0000-7000-8000-0000000000b2';
    private const string MIA = '0190a000-0000-7000-8000-0000000000b3';

    /**
     * @return iterable<string, array{int, string, string, bool}>
     */
    public static function rounds(): iterable
    {
        yield 'an open review round' => [2, 'review', 'open', true];
        yield 'an open shared draft, round 1' => [1, 'draft', 'open', true];
        yield 'a closed review round' => [2, 'review', 'closed', false];
        yield 'a closed shared draft' => [1, 'draft', 'closed', false];
        yield 'the report, round 0' => [0, 'report', 'open', false];
        yield 'a draft numbered 0' => [0, 'draft', 'open', false];
        yield 'an open checklist round' => [1, 'checklist', 'open', false];
    }

    #[Test]
    #[DataProvider('rounds')]
    public function onlyAnOpenReviewOrDraftRoundAfterTheReportIsAnOpenHandOffRound(int $number, string $kind, string $state, bool $isOpenHandOff): void
    {
        $openRound = OpenSharedRound::ofReviewRound(self::reviewRound($number, $kind, $state, true));

        self::assertSame($isOpenHandOff, null !== $openRound);
    }

    #[Test]
    public function theOpenRoundKeepsWhetherItsOwnerSharedItInAMove(): void
    {
        self::assertEquals(new OpenSharedRound(2, 'draft', new UserId(self::OMAR), true), OpenSharedRound::ofReviewRound(self::reviewRound(2, 'draft', 'open', true)));
        self::assertEquals(new OpenSharedRound(2, 'draft', new UserId(self::OMAR), false), OpenSharedRound::ofReviewRound(self::reviewRound(2, 'draft', 'open', false)));
    }

    #[Test]
    public function itsOwnersMoveOnHisTurnClosesItOnlyWhenHeSharedItInAMove(): void
    {
        $omar = new UserId(self::OMAR);
        $sharedNow = new OpenSharedRound(2, 'draft', $omar, false);
        $sharedAtHandOff = new OpenSharedRound(2, 'draft', $omar, true);

        self::assertFalse($sharedNow->isClosedByMoveOf($omar, true, $omar));
        self::assertSame(self::OMAR, $sharedNow->notReadyForUserId()?->toString());
        self::assertTrue($sharedNow->isClosedByMoveOf(new UserId(self::MIA), true, $omar), 'Whoever else\'s turn it is closes it.');
        self::assertTrue($sharedAtHandOff->isClosedByMoveOf($omar, true, $omar));
        self::assertNull($sharedAtHandOff->notReadyForUserId());
    }

    /**
     * @return iterable<string, array{int, string, string, bool, string, bool}> the round's number, kind and state, whether
     *                                                                          Omar shared it in a move, the mover, and
     *                                                                          whether their move on their turn closes it
     */
    public static function roundsAndMovers(): iterable
    {
        yield 'Mia on an open review round' => [2, 'review', 'open', false, self::MIA, true];
        yield 'Mia on an open draft Omar shared now' => [1, 'draft', 'open', false, self::MIA, true];
        yield 'Omar on an open draft he shared at hand-off' => [1, 'draft', 'open', true, self::OMAR, true];

        yield 'Omar on an open draft he shared now' => [1, 'draft', 'open', false, self::OMAR, false];
        yield 'Omar on his open review round' => [2, 'review', 'open', false, self::OMAR, false];
        yield 'Mia on a closed review round' => [2, 'review', 'closed', false, self::MIA, false];
        yield 'Mia on a closed shared draft' => [1, 'draft', 'closed', true, self::MIA, false];
        yield 'Mia on the report, round 0' => [0, 'report', 'open', false, self::MIA, false];
        yield 'Mia on an open checklist round' => [1, 'checklist', 'open', false, self::MIA, false];
    }

    /**
     * The round page's rule for its move-on verbs (RoundController). SHOP-1 is Omar's; Omar owns every round here.
     */
    #[Test]
    #[DataProvider('roundsAndMovers')]
    public function onlyAMoveOnThatClosesAnOpenHandOffRoundClosesTheRoundPagesRound(int $number, string $kind, string $state, bool $isSharedInAMove, string $moverId, bool $isClosed): void
    {
        $omar = new UserId(self::OMAR);

        self::assertSame($isClosed, true === OpenSharedRound::ofReviewRound(self::reviewRound($number, $kind, $state, $isSharedInAMove))?->isClosedByMoveOf(new UserId($moverId), true, $omar));
    }

    #[Test]
    public function anotherPersonsSharedDraftIsTheAssigneesFindings(): void
    {
        $omar = new UserId(self::OMAR);

        self::assertSame(self::OMAR, new OpenSharedRound(2, 'draft', new UserId(self::MIA), false)->findingsReadyFor($omar)?->toString());
        self::assertNull(new OpenSharedRound(2, 'review', new UserId(self::MIA), false)->findingsReadyFor($omar));
    }

    private static function reviewRound(int $number, string $kind, string $state, bool $isSharedInAMove): ReviewRoundView
    {
        return new ReviewRoundView('0190a000-0000-7000-8000-0000000000a1', $number, $kind, new UserId(self::OMAR), new \DateTimeImmutable('2026-11-05 10:00:00 UTC'), $state, [], $isSharedInAMove);
    }
}

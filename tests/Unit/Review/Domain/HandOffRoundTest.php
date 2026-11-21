<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\HandOffRound;
use App\Review\Domain\RoundKind;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-0048's rule of an open shared round on SHOP-1, whose assignee is Omar unless a case says nobody has it.
 */
final class HandOffRoundTest extends TestCase
{
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000003';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000005';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000006';

    /**
     * @return iterable<string, array{HandOffRound, string, bool, string|null, bool}> the round, the mover, whether it was
     *                                                                                their turn, the assignee, and whether
     *                                                                                the move closes it
     */
    public static function movesAndWhetherTheyCloseTheRound(): iterable
    {
        $omarsSharedNow = new HandOffRound(RoundKind::Draft, new UserId(self::OMAR), false);
        $omarsSharedAtHandOff = new HandOffRound(RoundKind::Draft, new UserId(self::OMAR), true);
        $miasFindings = new HandOffRound(RoundKind::Draft, new UserId(self::MIA), false);
        $lenasReview = new HandOffRound(RoundKind::Review, new UserId(self::LENA), false);

        yield 'the owner on his turn: his round shared now' => [$omarsSharedNow, self::OMAR, true, self::OMAR, false];
        yield 'the owner on his turn: his round shared at hand-off' => [$omarsSharedAtHandOff, self::OMAR, true, self::OMAR, true];
        yield 'someone else on her turn: his round shared now' => [$omarsSharedNow, self::MIA, true, self::OMAR, true];
        yield 'someone else outside her turn: his round shared now' => [$omarsSharedNow, self::MIA, false, self::OMAR, false];
        yield 'the owner outside his turn: his round shared at hand-off' => [$omarsSharedAtHandOff, self::OMAR, false, self::OMAR, false];
        yield 'the assignee outside his turn: Mia\'s findings' => [$miasFindings, self::OMAR, false, self::OMAR, true];
        yield 'the lead outside her turn: Mia\'s findings for Omar' => [$miasFindings, self::LENA, false, self::OMAR, false];
        yield 'Omar outside his turn, nobody has the ticket: Mia\'s draft' => [$miasFindings, self::OMAR, false, null, false];
        yield 'the owner on her turn: her review round' => [$lenasReview, self::LENA, true, self::OMAR, false];
        yield 'someone else on her turn: Lena\'s review round' => [$lenasReview, self::MIA, true, self::OMAR, true];
    }

    #[Test]
    #[DataProvider('movesAndWhetherTheyCloseTheRound')]
    public function aMoveClosesTheRoundOnTheMoversTurnOrOverTheAssigneesFindingsButNeverItsOwnersMoveWhenSharedOutsideAMove(HandOffRound $round, string $moverId, bool $isMoversTurn, ?string $assigneeId, bool $isClosed): void
    {
        self::assertSame($isClosed, $round->isClosedByMoveOf(new UserId($moverId), $isMoversTurn, null === $assigneeId ? null : new UserId($assigneeId)));
    }

    #[Test]
    public function aRoundSharedOutsideAMoveIsNotReadyForItsOwnerAndOneSharedInAMoveIsReadyForAnyone(): void
    {
        self::assertSame(self::OMAR, new HandOffRound(RoundKind::Draft, new UserId(self::OMAR), false)->notReadyForUserId()?->toString());
        self::assertSame(self::LENA, new HandOffRound(RoundKind::Review, new UserId(self::LENA), false)->notReadyForUserId()?->toString());
        self::assertNull(new HandOffRound(RoundKind::Draft, new UserId(self::OMAR), true)->notReadyForUserId());
    }

    #[Test]
    public function aSharedDraftOfAnyoneButTheAssigneeIsReadyForTheAssignee(): void
    {
        $assignee = new UserId(self::OMAR);

        self::assertSame(self::OMAR, new HandOffRound(RoundKind::Draft, new UserId(self::MIA), false)->findingsReadyFor($assignee)?->toString());
        self::assertNull(new HandOffRound(RoundKind::Draft, new UserId(self::OMAR), false)->findingsReadyFor($assignee), 'His own draft is no findings.');
        self::assertNull(new HandOffRound(RoundKind::Review, new UserId(self::MIA), false)->findingsReadyFor($assignee), 'A review round is no findings.');
        self::assertNull(new HandOffRound(RoundKind::Draft, new UserId(self::MIA), false)->findingsReadyFor(null), 'Nobody has the ticket.');
    }
}

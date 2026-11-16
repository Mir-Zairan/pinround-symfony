<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI;

use App\Review\UI\RoundCard;
use App\Review\UI\RoundsSectionView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoundsSectionViewTest extends TestCase
{
    #[Test]
    public function aSectionHasAPrivateDraftOnlyWhenOneOfItsCardsIsTheViewersUnsharedDraft(): void
    {
        $report = self::card('report', false);
        $sharedDraft = self::card('shared', false);
        $privateDraft = self::card('private', true);

        self::assertFalse(new RoundsSectionView([], 0)->hasPrivateDraft());
        self::assertFalse(new RoundsSectionView([$report, $sharedDraft], 0)->hasPrivateDraft());
        self::assertTrue(new RoundsSectionView([$report, $privateDraft, $sharedDraft], 0)->hasPrivateDraft());
    }

    #[Test]
    public function theOlderRoundsNoteCountsTheRoundsLeftOut(): void
    {
        self::assertNull(new RoundsSectionView([], 0)->olderRoundsNote());
        self::assertSame('1 older round is not shown.', new RoundsSectionView([], 1)->olderRoundsNote());
        self::assertSame('3 older rounds are not shown.', new RoundsSectionView([], 3)->olderRoundsNote());
    }

    #[Test]
    public function aNegativeCountOfRoundsLeftOutIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A count of rounds left out is not below 0.');

        new RoundsSectionView([], -1);
    }

    private static function card(string $roundId, bool $isPrivateDraft): RoundCard
    {
        return new RoundCard($roundId, 'round-'.$roundId, 'A headline', null, $isPrivateDraft, [], false);
    }
}

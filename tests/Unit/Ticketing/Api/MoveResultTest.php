<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Api;

use App\Ticketing\Api\DraftChoice;
use App\Ticketing\Api\MoveResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoveResultTest extends TestCase
{
    #[Test]
    public function aSharedDraftCarriesItsRoundNumber(): void
    {
        $result = new MoveResult(1, DraftChoice::Share, 4);

        self::assertSame(1, $result->clearedPingCount);
        self::assertSame(DraftChoice::Share, $result->draftOutcome);
        self::assertSame(4, $result->sharedRoundNumber);
    }

    /**
     * @return iterable<string, array{int, DraftChoice|null, int|null}>
     */
    public static function resultsThatContradictThemselves(): iterable
    {
        yield 'a negative ping count' => [-1, null, null];
        yield 'shared with no number' => [0, DraftChoice::Share, null];
        yield 'shared with number 0' => [0, DraftChoice::Share, 0];
        yield 'kept private with a number' => [0, DraftChoice::Keep, 2];
        yield 'no draft with a number' => [0, null, 2];
    }

    #[Test]
    #[DataProvider('resultsThatContradictThemselves')]
    public function aResultThatContradictsItselfIsRefused(int $cleared, ?DraftChoice $outcome, ?int $round): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MoveResult($cleared, $outcome, $round);
    }
}

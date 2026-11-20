<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\UI;

use App\Review\Api\RoundRefused;
use App\SharedKernel\UI\RoundStillOpenReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoundStillOpenReasonTest extends TestCase
{
    #[Test]
    public function theReasonNamesWhoGivesTheVerdictAndTheRoundsNumber(): void
    {
        self::assertSame('Round 2 is still open. Lena gives a verdict first.', RoundStillOpenReason::of(2, 'Lena'));
        self::assertSame('Round 12 is still open. Mia gives a verdict first.', RoundStillOpenReason::of(12, 'Mia'));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unknownVerdictGivers(): iterable
    {
        yield 'nobody known' => [null];
        yield 'an empty name' => [''];
        yield 'a blank name' => ['  '];
    }

    #[Test]
    #[DataProvider('unknownVerdictGivers')]
    public function withNobodyToNameTheReasonIsReviewsOwn(?string $firstName): void
    {
        self::assertSame('Round 2 is still open. Wait for its verdict before you share.', RoundStillOpenReason::of(2, $firstName));
        self::assertSame(\sprintf(RoundRefused::ROUND_STILL_OPEN, 2), RoundStillOpenReason::of(2, $firstName), 'The same words as Review\'s refusal.');
    }

    #[Test]
    public function theVerdictGiverIsAskedForTheirOwnVerdict(): void
    {
        self::assertSame('Round 3 is still open. Give your verdict first.', RoundStillOpenReason::forTheVerdictGiver(3));
    }
}

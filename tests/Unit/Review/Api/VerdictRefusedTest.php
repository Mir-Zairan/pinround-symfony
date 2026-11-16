<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Api;

use App\Review\Api\VerdictRefused;
use App\Review\Domain\Area;
use App\Review\Domain\PinToCheck;
use App\Review\Domain\ReviewedRound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VerdictRefusedTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function reasonsAndWhetherTheyAreAMissingPage(): iterable
    {
        yield 'no such round' => [VerdictRefused::NOT_FOUND, true];
        yield 'no such area' => [VerdictRefused::AREA_NOT_FOUND, true];
        yield 'no such pin' => [VerdictRefused::PIN_NOT_FOUND, true];
        yield 'a pin changed in another tab' => [VerdictRefused::PIN_CHANGED, false];
        yield 'no verdict picked' => [VerdictRefused::PICK_A_VERDICT, false];
        yield 'an unknown pin verdict' => [VerdictRefused::UNKNOWN_PIN_VERDICT, false];
        yield 'a closed round' => [ReviewedRound::CLOSED, false];
        yield 'a round that takes no verdicts' => [ReviewedRound::TAKES_NO_VERDICTS, false];
        yield 'a pin with a verdict already' => [PinToCheck::VERDICT_GIVEN, false];
        yield 'the not-found text in another case' => [strtoupper(VerdictRefused::NOT_FOUND), false];
    }

    #[Test]
    #[DataProvider('reasonsAndWhetherTheyAreAMissingPage')]
    public function onlyTheThreeNotFoundReasonsAreAMissingPage(string $reason, bool $isNotFound): void
    {
        self::assertSame($isNotFound, new VerdictRefused($reason)->isNotFound());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textsOfTheDomainAndTheRefusal(): iterable
    {
        yield 'no verdict picked' => [Area::VERDICT_MISSING, VerdictRefused::PICK_A_VERDICT];
    }

    #[Test]
    #[DataProvider('textsOfTheDomainAndTheRefusal')]
    public function theRefusalShowsTheDomainsText(string $domainText, string $refusalText): void
    {
        self::assertSame($domainText, $refusalText);
    }
}

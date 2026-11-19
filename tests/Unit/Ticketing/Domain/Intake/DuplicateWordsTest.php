<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Domain\Intake;

use App\Ticketing\Domain\Intake\DuplicateWords;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DuplicateWordsTest extends TestCase
{
    #[Test]
    public function wordsAreLowercaseLongerThanThreeAndNoStopWordsEachOnce(): void
    {
        self::assertSame(['cart', 'total', 'counted', 'twice', 'größe', '2026'], DuplicateWords::of('The Cart TOTAL is counted twice!', "with the cart\ngröße 2026 on a big day"));
    }

    #[Test]
    public function aTitleMatchesWithThreeSharedWordsThatAreAtLeastThirtyPercentOfIt(): void
    {
        $report = DuplicateWords::of('Cart total counted twice after coupon');

        self::assertSame(3, DuplicateWords::sharedWith($report, 'Cart total counted wrong'), '3 of 4 title words.');
        self::assertNull(DuplicateWords::sharedWith($report, 'Cart total wrong'), 'Only two shared.');
        self::assertSame(3, DuplicateWords::sharedWith($report, 'Cart total counted alpha bravo charlie delta echoes foxtrot golfs'), '3 of 10 title words is 30 %.');
        self::assertNull(DuplicateWords::sharedWith($report, 'Cart total counted alpha bravo charlie delta echoes foxtrot golfs hotel'), '3 of 11 is under 30 %.');
        self::assertNull(DuplicateWords::sharedWith($report, 'The and for'), 'A title of stop words only has no words.');
    }
}

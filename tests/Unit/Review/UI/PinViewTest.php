<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI;

use App\Review\UI\PinView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PinViewTest extends TestCase
{
    /**
     * @return iterable<string, array{float, float}>
     */
    public static function positionsOutsideThePicture(): iterable
    {
        yield 'x below 0' => [-0.1, 50.0];
        yield 'x above 100' => [100.1, 50.0];
        yield 'y below 0' => [50.0, -0.1];
        yield 'y above 100' => [50.0, 100.1];
        yield 'x not a number' => [\NAN, 50.0];
        yield 'y infinite' => [50.0, \INF];
    }

    #[Test]
    #[DataProvider('positionsOutsideThePicture')]
    public function itRefusesAPositionOutsideThePicture(float $x, float $y): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A pin must sit inside the picture: x and y go from 0 to 100.');

        new PinView(1, $x, $y, 'Header is cut off');
    }

    #[Test]
    public function itAcceptsTheCornersOfThePicture(): void
    {
        self::assertSame(100.0, new PinView(1, 100.0, 0.0, '')->xPercent);
        self::assertSame(100.0, new PinView(2, 0.0, 100.0, '')->yPercent);
    }

    #[Test]
    public function itRefusesANoteOverOneThousandCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A pin note holds at most 1000 characters.');

        new PinView(1, 10.0, 10.0, str_repeat('é', 1001));
    }

    #[Test]
    public function itAcceptsANoteOfExactlyOneThousandMultibyteCharacters(): void
    {
        self::assertSame(1000, mb_strlen(new PinView(1, 10.0, 10.0, str_repeat('é', 1000))->note));
    }

    #[Test]
    public function itRefusesANumberBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A pin number starts at 1.');

        new PinView(0, 10.0, 10.0, '');
    }

    #[Test]
    public function theAccessibleNameHoldsTheNumberAndTheNote(): void
    {
        self::assertSame('Pin 2: header is cut off', new PinView(2, 10.0, 10.0, 'header is cut off')->accessibleName());
        self::assertSame('Pin 3', new PinView(3, 10.0, 10.0, " \r\n ")->accessibleName());
    }

    #[Test]
    public function theSingleLineNoteReplacesLineBreaksAndControlCharacters(): void
    {
        $pin = new PinView(1, 10.0, 10.0, "first\r\nsecond\u{2028}third\u{1b}[31m  fourth\n");

        self::assertSame('first second third [31m fourth', $pin->singleLineNote());
    }

    #[Test]
    public function theBubbleOpensToTheLeftOnlyPastSixtyPercent(): void
    {
        self::assertFalse(new PinView(1, 60.0, 10.0, 'a')->isBubbleOnLeft());
        self::assertTrue(new PinView(1, 60.1, 10.0, 'a')->isBubbleOnLeft());
    }
}

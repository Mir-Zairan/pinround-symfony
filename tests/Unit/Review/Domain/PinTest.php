<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\PinSource;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PinTest extends TestCase
{
    private const string PIN = '0197a3c0-0000-7000-8000-000000000a01';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000b03';

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function positionsOutsideThePicture(): iterable
    {
        yield 'x below 0' => [-0.01, 50.0];
        yield 'x above 100' => [100.01, 50.0];
        yield 'y below 0' => [50.0, -0.01];
        yield 'y above 100' => [50.0, 100.01];
        yield 'x not a number' => [\NAN, 50.0];
        yield 'y infinite' => [50.0, \INF];
        yield 'x above 100 but rounds to 100' => [100.004, 50.0];
        yield 'y above 100 but rounds to 100' => [50.0, 100.004];
    }

    #[Test]
    #[DataProvider('positionsOutsideThePicture')]
    public function itRefusesAPositionOutsideThePicture(float $x, float $y): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A pin must sit inside the picture: x and y go from 0 to 100.');

        self::pin(1, $x, $y, 'Header is cut off');
    }

    #[Test]
    public function itAcceptsEveryCornerOfThePicture(): void
    {
        $corners = [[0.0, 0.0], [100.0, 0.0], [0.0, 100.0], [100.0, 100.0]];

        foreach ($corners as [$x, $y]) {
            $pin = self::pin(1, $x, $y, '');

            self::assertSame($x, $pin->xPercent());
            self::assertSame($y, $pin->yPercent());
        }
    }

    #[Test]
    public function itRoundsThePositionToTwoDecimalsAfterTheCheck(): void
    {
        $pin = self::pin(1, 33.336, 99.999, '');

        self::assertSame(33.34, $pin->xPercent());
        self::assertSame(100.0, $pin->yPercent());
    }

    #[Test]
    public function itRefusesANumberBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A pin number starts at 1.');

        self::pin(0, 10.0, 10.0, 'Header is cut off');
    }

    #[Test]
    public function itAcceptsNumberOne(): void
    {
        self::assertSame(1, self::pin(1, 10.0, 10.0, '')->number());
    }

    #[Test]
    public function itRefusesANoteOverTheCapCountingCharactersNotBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A pin note holds at most 1000 characters.');

        self::pin(1, 10.0, 10.0, str_repeat('é', Pin::NOTE_MAX_LENGTH + 1));
    }

    #[Test]
    public function itAcceptsANoteOfExactlyTheCapInMultibyteCharacters(): void
    {
        $note = str_repeat('é', Pin::NOTE_MAX_LENGTH);

        self::assertSame($note, self::pin(1, 10.0, 10.0, $note)->note());
    }

    #[Test]
    public function itTrimsTheNoteBeforeCountingIt(): void
    {
        $note = str_repeat('a', Pin::NOTE_MAX_LENGTH);

        self::assertSame($note, self::pin(1, 10.0, 10.0, " \r\n".$note."\t ")->note());
    }

    #[Test]
    public function aBlankNoteStaysAnEmptyStringNotNull(): void
    {
        self::assertSame('', self::pin(1, 10.0, 10.0, " \r\n ")->note());
    }

    #[Test]
    public function aPlacedPinIsAHumanPinWithNoAiStateAndNoVerdict(): void
    {
        $pin = self::pin(2, 10.0, 20.0, 'Header is cut off');

        self::assertSame(PinSource::Human, $pin->source());
        self::assertNull($pin->aiState());
        self::assertNull($pin->verdict());
        self::assertSame(self::PIN, $pin->id()->toString());
        self::assertSame(self::MIA, $pin->authorId()->toString());
        self::assertSame(2, $pin->number());
    }

    private static function pin(int $number, float $x, float $y, string $note): Pin
    {
        return Pin::placedBy(new PinId(self::PIN), $number, $x, $y, $note, new UserId(self::MIA));
    }
}

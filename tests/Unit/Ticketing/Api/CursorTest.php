<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\Cursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{
    private const string STEP = '0197a3c0-0000-7000-8000-00000000c001';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000c002';

    #[Test]
    public function itKeepsTheLastRowsPlaceInTheStep(): void
    {
        $cursor = new Cursor(self::STEP, 4, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'), new TicketId(self::TICKET));

        self::assertSame([self::STEP, 4, '2027-03-15 10:00:00', self::TICKET, null], [$cursor->stepId, $cursor->priorityRank, $cursor->updatedAt->format('Y-m-d H:i:s'), $cursor->ticketId->toString(), $cursor->matchPosition]);
    }

    #[Test]
    public function underASearchItKeepsTheLastRowsSearchOrderValuesExactly(): void
    {
        $matchPosition = Cursor::matchPositionOf(true, '0.169808963686');
        $cursor = new Cursor(self::STEP, 4, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'), new TicketId(self::TICKET), $matchPosition);

        self::assertSame(['1_0.169808963686', [true, '0.169808963686']], [$cursor->matchPosition, $cursor->searchOrderValues()]);
        self::assertSame([false, '12.000000000000'], new Cursor(self::STEP, 4, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'), new TicketId(self::TICKET), Cursor::matchPositionOf(false, '12.000000000000'))->searchOrderValues());
    }

    #[Test]
    public function withoutASearchItHasNoSearchOrderValues(): void
    {
        self::assertNull(new Cursor(self::STEP, 4, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'), new TicketId(self::TICKET))->searchOrderValues());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedMatchPositions(): iterable
    {
        yield 'empty' => [''];
        yield 'a flag other than 0 or 1' => ['2_0.169808963686'];
        yield 'no score' => ['1_'];
        yield 'a negative score' => ['0_-0.169808963686'];
        yield 'a score rounded to fewer decimals' => ['0_0.1698'];
        yield 'a score with an exponent' => ['0_1.698089636860e-01'];
        yield 'a score with a leading zero' => ['0_01.000000000000'];
        yield 'a trailing newline' => ["0_0.169808963686\n"];
        yield 'an old ordinal position' => ['50'];
    }

    #[Test]
    #[DataProvider('refusedMatchPositions')]
    public function itRefusesAMatchPositionTheDatabaseCannotHaveWritten(string $matchPosition): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('A cursor\'s match position is the exact-key flag and the match score as the database writes them.'));
        new Cursor(self::STEP, 4, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'), new TicketId(self::TICKET), $matchPosition);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function refusedValues(): iterable
    {
        yield 'a step id that is no uuid' => ['step-1', 0];
        yield 'an uppercase step id' => [strtoupper(self::STEP), 0];
        yield 'a rank below blocker' => [self::STEP, -1];
        yield 'a rank above minor' => [self::STEP, 5];
    }

    #[Test]
    #[DataProvider('refusedValues')]
    public function itRefusesAStepIdOrRankTheListCannotHave(string $stepId, int $priorityRank): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Cursor($stepId, $priorityRank, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'), new TicketId(self::TICKET));
    }
}

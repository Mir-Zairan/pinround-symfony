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
    public function underASearchItKeepsTheLastRowsMatchPosition(): void
    {
        self::assertSame(1, new Cursor(self::STEP, 4, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'), new TicketId(self::TICKET), 1)->matchPosition);
    }

    #[Test]
    public function itRefusesAMatchPositionBelowOne(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('A cursor\'s match position starts at 1.'));
        new Cursor(self::STEP, 4, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'), new TicketId(self::TICKET), 0);
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

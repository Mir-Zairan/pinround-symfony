<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\CheckItem;
use App\Review\Domain\CheckItemId;
use App\Review\Domain\CheckItemState;
use App\Review\Domain\Checklist;
use App\Review\Domain\PinId;
use App\Review\Domain\ReportPins;
use App\Review\Domain\RoundId;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChecklistTest extends TestCase
{
    private const string ROUND = '0197a3c0-0000-7000-8000-000000000e02';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000f01';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000001001';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000b01';
    private const string FIRST_PIN = '0197a3c0-0000-7000-8000-000000000a01';
    private const string SECOND_PIN = '0197a3c0-0000-7000-8000-000000000a02';
    private const string THIRD_PIN = '0197a3c0-0000-7000-8000-000000000a03';
    private const string CREATED_AT = '2027-03-16 10:30:00+00:00';

    #[Test]
    public function aChecklistHasOneOpenItemPerPinOfRoundZeroInItsOrderAndIsOwnedByTheReporter(): void
    {
        // Pins out of id order, so the items follow Round 0's order and not the ids'.
        $checklist = self::checklistOf([self::THIRD_PIN, self::FIRST_PIN, self::SECOND_PIN], 2);

        self::assertNotNull($checklist);
        self::assertSame(
            [self::ROUND, self::ACME_FOODS, self::TICKET, self::LENA, 2, self::CREATED_AT],
            [$checklist->id()->toString(), $checklist->organizationId()->toString(), $checklist->ticketId()->toString(), $checklist->ownerId()->toString(), $checklist->number(), $checklist->createdAt()->format('Y-m-d H:i:sP')],
        );
        self::assertSame(
            [
                ['0197a3c0-0000-7000-8000-000000001e01', self::THIRD_PIN, self::ROUND, self::TICKET, CheckItemState::Open, null],
                ['0197a3c0-0000-7000-8000-000000001e02', self::FIRST_PIN, self::ROUND, self::TICKET, CheckItemState::Open, null],
                ['0197a3c0-0000-7000-8000-000000001e03', self::SECOND_PIN, self::ROUND, self::TICKET, CheckItemState::Open, null],
            ],
            array_map(static fn (CheckItem $item): array => [$item->id()->toString(), $item->pinId()->toString(), $item->roundId()->toString(), $item->ticketId()->toString(), $item->state(), $item->changedBy()], $checklist->items()),
        );
    }

    #[Test]
    public function aRoundZeroWithNoPinsGetsNoChecklist(): void
    {
        self::assertNull(self::checklistOf([], 1));
    }

    #[Test]
    public function aChecklistIsNeverRoundZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A checklist round gets a number from 1; Round 0 is the report.');

        self::checklistOf([self::FIRST_PIN], 0);
    }

    #[Test]
    public function aChecklistTakesNumberOne(): void
    {
        self::assertSame(1, self::checklistOf([self::FIRST_PIN], 1)?->number());
    }

    #[Test]
    public function aPinComingTwiceIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A checklist has one item per pin.');

        self::checklistOf([self::FIRST_PIN, self::SECOND_PIN, strtoupper(self::FIRST_PIN)], 1);
    }

    /**
     * @param list<string> $pinIds
     */
    private static function checklistOf(array $pinIds, int $number): ?Checklist
    {
        $issuedItemIds = 0;

        return Checklist::fromReport(
            new RoundId(self::ROUND),
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            new ReportPins(new UserId(self::LENA), array_map(static fn (string $pinId): PinId => new PinId($pinId), $pinIds)),
            $number,
            static function () use (&$issuedItemIds): CheckItemId {
                return new CheckItemId(\sprintf('0197a3c0-0000-7000-8000-%012x', 0x1E00 + ++$issuedItemIds));
            },
            new \DateTimeImmutable(self::CREATED_AT),
        );
    }
}

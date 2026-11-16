<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Domain\CheckItem;
use App\Review\Domain\CheckItemId;
use App\Review\Domain\CheckItemState;
use App\Review\Domain\PinId;
use App\Review\Domain\RoundId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckItemTest extends TestCase
{
    private const string ITEM = '0197a3c0-0000-7000-8000-000000001e01';
    private const string ROUND = '0197a3c0-0000-7000-8000-000000000e01';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000001001';
    private const string PIN = '0197a3c0-0000-7000-8000-000000000a01';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000b01';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000b03';
    private const string EARLIER = '2027-03-15 09:00:00+00:00';
    private const string NOW = '2027-03-16 10:30:00+00:00';

    #[Test]
    public function aNewItemIsOpenAndUnchanged(): void
    {
        $item = CheckItem::open(new CheckItemId(self::ITEM), new RoundId(self::ROUND), new TicketId(self::TICKET), new PinId(self::PIN));

        self::assertSame(
            [self::ITEM, self::ROUND, self::TICKET, self::PIN, CheckItemState::Open, CheckItemState::Open, null, null],
            [$item->id()->toString(), $item->roundId()->toString(), $item->ticketId()->toString(), $item->pinId()->toString(), $item->state(), $item->savedState(), $item->changedBy(), $item->changedAt()],
        );
    }

    /**
     * @return iterable<string, array{CheckItemState, string, CheckItemState}>
     */
    public static function allowedMoves(): iterable
    {
        yield 'an open item marked fixed' => [CheckItemState::Open, 'markFixed', CheckItemState::Fixed];
        yield 'an item sent back marked fixed again' => [CheckItemState::NotFixed, 'markFixed', CheckItemState::Fixed];
        yield 'a fixed item confirmed OK' => [CheckItemState::Fixed, 'markOk', CheckItemState::Ok];
        yield 'a fixed item sent back as not fixed' => [CheckItemState::Fixed, 'markNotFixed', CheckItemState::NotFixed];
    }

    #[Test]
    #[DataProvider('allowedMoves')]
    public function theMoveSetsTheStateAndWhoMadeItWhenAndKeepsTheSavedState(CheckItemState $from, string $move, CheckItemState $to): void
    {
        $item = self::itemIn($from);

        self::move($item, $move);

        self::assertSame(
            [$to, $from, self::MIA, self::NOW],
            [$item->state(), $item->savedState(), $item->changedBy()?->toString(), $item->changedAt()?->format('Y-m-d H:i:sP')],
        );
    }

    /**
     * @return iterable<string, array{CheckItemState, string, string}>
     */
    public static function refusedMoves(): iterable
    {
        yield 'a fixed item marked fixed' => [CheckItemState::Fixed, 'markFixed', 'This check is marked fixed already.'];
        yield 'an OK item marked fixed' => [CheckItemState::Ok, 'markFixed', 'This check is confirmed OK, so it no longer changes.'];
        yield 'an open item confirmed OK' => [CheckItemState::Open, 'markOk', 'Only a check marked fixed can be confirmed OK.'];
        yield 'an OK item confirmed OK' => [CheckItemState::Ok, 'markOk', 'Only a check marked fixed can be confirmed OK.'];
        yield 'an item sent back confirmed OK' => [CheckItemState::NotFixed, 'markOk', 'Only a check marked fixed can be confirmed OK.'];
        yield 'an open item sent back' => [CheckItemState::Open, 'markNotFixed', 'Only a check marked fixed can be sent back as not fixed.'];
        yield 'an OK item sent back' => [CheckItemState::Ok, 'markNotFixed', 'Only a check marked fixed can be sent back as not fixed.'];
        yield 'an item sent back sent back again' => [CheckItemState::NotFixed, 'markNotFixed', 'Only a check marked fixed can be sent back as not fixed.'];
    }

    #[Test]
    #[DataProvider('refusedMoves')]
    public function theMoveIsRefusedWithItsReasonAndTheItemStaysAsItWas(CheckItemState $from, string $move, string $reason): void
    {
        $item = self::itemIn($from);

        try {
            self::move($item, $move);
            self::fail('The move was not refused.');
        } catch (\DomainException $refusal) {
            self::assertSame($reason, $refusal->getMessage());
        }

        $expectedChangedBy = CheckItemState::Open === $from ? null : self::LENA;
        $expectedChangedAt = CheckItemState::Open === $from ? null : self::EARLIER;
        self::assertSame(
            [$from, $expectedChangedBy, $expectedChangedAt],
            [$item->state(), $item->changedBy()?->toString(), $item->changedAt()?->format('Y-m-d H:i:sP')],
        );
    }

    #[Test]
    public function aStoredItemComesBackAsItWasStored(): void
    {
        $item = self::itemIn(CheckItemState::NotFixed);

        self::assertSame(
            [CheckItemState::NotFixed, CheckItemState::NotFixed, self::LENA, self::EARLIER],
            [$item->state(), $item->savedState(), $item->changedBy()?->toString(), $item->changedAt()?->format('Y-m-d H:i:sP')],
        );
    }

    /**
     * @return iterable<string, array{CheckItemState, ?string, ?string, string}>
     */
    public static function storedItemsRefused(): iterable
    {
        yield 'who changed it with no time' => [CheckItemState::Fixed, self::LENA, null, 'A check item has who changed it exactly when it has when.'];
        yield 'a time with nobody who changed it' => [CheckItemState::Fixed, null, self::EARLIER, 'A check item has who changed it exactly when it has when.'];
        yield 'fixed with nobody who changed it' => [CheckItemState::Fixed, null, null, 'A check item that nobody changed is open.'];
    }

    #[Test]
    #[DataProvider('storedItemsRefused')]
    public function aStoredItemTheDomainCouldNotReachIsRefused(CheckItemState $state, ?string $changedBy, ?string $changedAt, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($reason);

        CheckItem::restored(
            new CheckItemId(self::ITEM),
            new RoundId(self::ROUND),
            new TicketId(self::TICKET),
            new PinId(self::PIN),
            $state,
            null === $changedBy ? null : new UserId($changedBy),
            null === $changedAt ? null : new \DateTimeImmutable($changedAt),
        );
    }

    #[Test]
    public function anOpenItemNeverChangedIsRestored(): void
    {
        $item = CheckItem::restored(new CheckItemId(self::ITEM), new RoundId(self::ROUND), new TicketId(self::TICKET), new PinId(self::PIN), CheckItemState::Open, null, null);

        self::assertSame([CheckItemState::Open, null], [$item->state(), $item->changedBy()]);
    }

    #[Test]
    public function aCheckItemIdIsAUuidV7KeptInLowercase(): void
    {
        self::assertSame(self::ITEM, new CheckItemId(strtoupper(self::ITEM))->toString());
        self::assertTrue(new CheckItemId(self::ITEM)->equals(new CheckItemId(strtoupper(self::ITEM))));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A check item id must be a canonical UUIDv7.');

        new CheckItemId(self::ITEM."\n");
    }

    /**
     * An item in $state as a read gives it: one that left open was last changed by Lena, earlier.
     */
    private static function itemIn(CheckItemState $state): CheckItem
    {
        $isOpen = CheckItemState::Open === $state;

        return CheckItem::restored(
            new CheckItemId(self::ITEM),
            new RoundId(self::ROUND),
            new TicketId(self::TICKET),
            new PinId(self::PIN),
            $state,
            $isOpen ? null : new UserId(self::LENA),
            $isOpen ? null : new \DateTimeImmutable(self::EARLIER),
        );
    }

    private static function move(CheckItem $item, string $move): void
    {
        $actorId = new UserId(self::MIA);
        $at = new \DateTimeImmutable(self::NOW);
        match ($move) {
            'markFixed' => $item->markFixed($actorId, $at),
            'markOk' => $item->markOk($actorId, $at),
            'markNotFixed' => $item->markNotFixed($actorId, $at),
            default => throw new \LogicException(\sprintf('No move %s.', $move)),
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application;

use App\Review\Domain\CheckItemState;
use App\Review\Domain\Checklist;
use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\ReportPins;
use App\Review\Domain\Round;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\Screenshot;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The double is only worth using while it behaves like DbalChecklists, so the rules DbalChecklistsTest proves on the
 * database are pinned here too.
 */
final class InMemoryChecklistsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000f01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000000b';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000001001';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000b01';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000b03';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000000d01';
    private const string UNKNOWN_PIN = '0197a3c0-0000-7000-8000-000000000a77';
    private const string CREATED_AT = '2027-03-16 10:30:00+00:00';

    private TenantContext $tenantContext;
    private InMemoryRounds $rounds;
    private InMemoryChecklists $checklists;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new class implements TenantSwitchGuard {
            public function prepareSwitch(): void
            {
            }
        });
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->rounds = new InMemoryRounds($this->tenantContext);
        $this->checklists = new InMemoryChecklists($this->rounds, $this->tenantContext);
    }

    #[Test]
    public function theReportPinsComeByScreenshotPositionThenPinNumberWithTheReporter(): void
    {
        $this->addReport();

        $reportPins = $this->checklists->reportPinsOf(new TicketId(self::TICKET));

        self::assertNotNull($reportPins);
        self::assertSame(self::LENA, $reportPins->reporterId()->toString());
        self::assertSame(
            ['0197a3c0-0000-7000-8000-000000008205', '0197a3c0-0000-7000-8000-000000008204', '0197a3c0-0000-7000-8000-000000008202'],
            array_map(static fn (PinId $pinId): string => $pinId->toString(), $reportPins->pinIds()),
        );
        self::assertNull($this->checklists->reportPinsOf(new TicketId('0197a3c0-0000-7000-8000-000000001002')));
    }

    #[Test]
    public function aChecklistIsARoundOfTheTicketSoTheNextFreeNumberGoesPastIt(): void
    {
        $this->addReport();
        $checklist = $this->checklist(1);

        $this->checklists->add($checklist);

        self::assertSame([RoundKind::Checklist, 1, self::LENA], [$this->rounds->stored[1]->kind(), $this->rounds->stored[1]->number(), $this->rounds->stored[1]->ownerId()->toString()]);
        self::assertSame(2, $this->rounds->nextFreeNumberOf(new TicketId(self::TICKET)));
        self::assertCount(3, $this->checklists->storedItems);
    }

    #[Test]
    public function aSecondChecklistOfTheTicketIsASlotTakenAndStoresNothing(): void
    {
        $this->addReport();
        $this->checklists->add($this->checklist(1));
        $roundsBefore = $this->rounds->stored;
        $itemsBefore = $this->checklists->storedItems;

        try {
            $this->checklists->add($this->checklist(2));
            self::fail('The second checklist was not refused.');
        } catch (RoundSlotTaken $refusal) {
            self::assertSame('The ticket has this round already.', $refusal->getMessage());
        }

        self::assertSame([$roundsBefore, $itemsBefore], [$this->rounds->stored, $this->checklists->storedItems]);
    }

    #[Test]
    public function aChecklistOnANumberTheTicketHasIsASlotTakenAndStoresNothing(): void
    {
        $this->addReport();
        $draft = Round::draft($this->rounds->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::MIA), new \DateTimeImmutable(self::CREATED_AT));
        $draft->share(new UserId(self::MIA), 1, new \DateTimeImmutable(self::CREATED_AT));
        $this->rounds->add($draft);

        $this->expectException(RoundSlotTaken::class);
        try {
            $this->checklists->add($this->checklist(1));
        } finally {
            self::assertSame([2, []], [\count($this->rounds->stored), $this->checklists->storedItems]);
        }
    }

    #[Test]
    public function theSameChecklistAddedAgainIsALogicExceptionAndNotASlot(): void
    {
        $this->addReport();
        $checklist = $this->checklist(1);
        $this->checklists->add($checklist);

        $this->expectExceptionObject(new \LogicException(\sprintf('Round %s is added already; a round is added once.', $checklist->id()->toString())));
        try {
            $this->checklists->add($checklist);
        } finally {
            self::assertSame([2, 3], [\count($this->rounds->stored), \count($this->checklists->storedItems)]);
        }
    }

    #[Test]
    public function anItemOnAPinNotStoredIsALogicExceptionAndStoresNothing(): void
    {
        $this->addReport();
        $checklist = Checklist::fromReport($this->rounds->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new ReportPins(new UserId(self::LENA), [new PinId(self::UNKNOWN_PIN)]), 1, $this->checklists->nextItemIdentity(...), new \DateTimeImmutable(self::CREATED_AT));
        self::assertNotNull($checklist);

        $this->expectExceptionObject(new \LogicException(InMemoryChecklists::ITEM_REFUSED));
        try {
            $this->checklists->add($checklist);
        } finally {
            self::assertSame([1, []], [\count($this->rounds->stored), $this->checklists->storedItems]);
        }
    }

    #[Test]
    public function aLockedItemIsSavedOverTheStateItWasReadAtAndAStaleSaveIsRefused(): void
    {
        $this->addReport();
        $checklist = $this->checklist(1);
        $this->checklists->add($checklist);
        $itemId = $checklist->items()[1]->id();

        $item = $this->checklists->lockedItem($itemId);
        self::assertNotNull($item);
        $item->markFixed(new UserId(self::MIA), new \DateTimeImmutable(self::CREATED_AT));
        $this->checklists->saveItem($item);
        $item->markOk(new UserId(self::MIA), new \DateTimeImmutable(self::CREATED_AT));

        try {
            $this->checklists->saveItem($item);
            self::fail('A save over a state the item no longer has was not refused.');
        } catch (\LogicException $refusal) {
            self::assertStringContainsString('changed since it was read', $refusal->getMessage());
        }

        $stored = $this->checklists->lockedItem($itemId);
        self::assertSame([CheckItemState::Fixed, self::MIA], [$stored?->state(), $stored?->changedBy()?->toString()]);
        self::assertSame([$itemId->toString(), $itemId->toString()], $this->checklists->lockedItemIds);
        self::assertSame(
            [CheckItemState::Open, CheckItemState::Fixed, CheckItemState::Open],
            array_map(static fn (array $stored): CheckItemState => $stored[1]->state(), array_values($this->checklists->storedItems)),
        );
    }

    #[Test]
    public function anotherOrganizationSeesNoneOfTheChecklistAndCannotSaveItsItems(): void
    {
        $this->addReport();
        $checklist = $this->checklist(1);
        $this->checklists->add($checklist);
        $item = $checklist->items()[0];
        $item->markFixed(new UserId(self::MIA), new \DateTimeImmutable(self::CREATED_AT));

        $this->tenantContext->set(new OrganizationId(self::BETA_BAKERY));

        self::assertSame([null, null], [$this->checklists->reportPinsOf(new TicketId(self::TICKET)), $this->checklists->lockedItem($item->id())]);
        $this->expectException(\LogicException::class);
        try {
            $this->checklists->saveItem($item);
        } finally {
            self::assertSame(CheckItemState::Open, $this->checklists->storedItems[$item->id()->toString()][1]->state());
        }
    }

    /**
     * Round 0 with two pictures given out of order and pins out of number order, so the order reportPinsOf gives comes
     * from the positions and numbers.
     */
    private function addReport(): void
    {
        $lena = new UserId(self::LENA);
        $second = Screenshot::of($this->rounds->nextScreenshotIdentity(), self::MEDIA, 1, null, null, [
            Pin::placedBy($this->rounds->nextPinIdentity(), 1, 10.0, 10.0, 'Too small', $lena),
        ]);
        $first = Screenshot::of($this->rounds->nextScreenshotIdentity(), self::MEDIA, 0, null, null, [
            Pin::placedBy($this->rounds->nextPinIdentity(), 2, 20.0, 20.0, 'Wrong total', $lena),
            Pin::placedBy($this->rounds->nextPinIdentity(), 1, 30.0, 30.0, 'Missing price', $lena),
        ]);
        $this->rounds->add(Round::report($this->rounds->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), $lena, [$second, $first], new \DateTimeImmutable('2027-03-15 09:00:00+00:00')));
    }

    private function checklist(int $number): Checklist
    {
        $reportPins = $this->checklists->reportPinsOf(new TicketId(self::TICKET)) ?? throw new \LogicException('The ticket has no Round 0.');

        return Checklist::fromReport($this->rounds->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), $reportPins, $number, $this->checklists->nextItemIdentity(...), new \DateTimeImmutable(self::CREATED_AT))
            ?? throw new \LogicException('Round 0 has no pins.');
    }
}

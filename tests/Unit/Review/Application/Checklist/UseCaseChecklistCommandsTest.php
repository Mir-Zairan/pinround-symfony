<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Application\Checklist;

use App\Review\Api\CheckItemChange;
use App\Review\Api\ChecklistCommands;
use App\Review\Api\ChecklistRefused;
use App\Review\Api\Event\ChecklistItemChanged;
use App\Review\Application\Checklist\UseCaseChecklistCommands;
use App\Review\Domain\CheckItem;
use App\Review\Domain\CheckItemState;
use App\Review\Domain\Pin;
use App\Review\Domain\Round;
use App\Review\Domain\RoundKind;
use App\Review\Domain\Screenshot;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Conversation\Application\InMemoryPingClearing;
use App\Tests\Unit\Review\Application\InMemoryChecklists;
use App\Tests\Unit\Review\Application\InMemoryRounds;
use App\Tests\Unit\Review\Application\RecordingReviewEvents;
use App\Tests\Unit\Review\UI\Media\InMemoryTicketTurnQueries;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * UseCaseChecklistCommands over InMemoryChecklists and InMemoryRounds, which keep what DbalChecklists and DbalRounds
 * keep: the tenant's rows only, one checklist round per ticket and the conditional item write. Lena reported SHOP-1
 * with two pictures; Omar marks its items fixed and Mia checks them, in Acme Foods. Beta Bakery has a checklist of its
 * own, and Omar and Mia have open pings on SHOP-1 and on another ticket, which a refusal must leave alone. Every refusal
 * is proven with its reason, the stored items exactly as they were, no event and no ping cleared.
 */
final class UseCaseChecklistCommandsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000007a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000007a02';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000007a11';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-000000007a12';
    private const string TICKET_WITHOUT_PINS = '0197a3c0-0000-7000-8000-000000007a13';
    private const string TICKET_WITHOUT_REPORT = '0197a3c0-0000-7000-8000-000000007a14';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-000000007a15';
    private const string BROKEN_TICKET = '0197a3c0-0000-7000-8000-000000007a16';
    private const string LENA = '0197a3c0-0000-7000-8000-000000007a21';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000007a22';
    private const string MIA = '0197a3c0-0000-7000-8000-000000007a23';
    private const string MEDIA = '0197a3c0-0000-7000-8000-000000007a31';
    private const string UNKNOWN_ITEM = '0197a3c0-0000-7000-8000-000000007aff';
    private const string REPORTED_AT = '2027-03-15 09:00:00+00:00';
    private const string CREATED_AT = '2027-03-15 10:00:00+00:00';
    private const string NOW = '2027-03-16 09:30:00+00:00';

    private TenantContext $tenantContext;
    private InMemoryRounds $rounds;
    private InMemoryChecklists $checklists;
    private RecordingReviewEvents $events;
    private InMemoryPingClearing $pingClearing;
    private InMemoryTicketTurnQueries $ticketTurnQueries;
    private UseCaseChecklistCommands $commands;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->rounds = new InMemoryRounds($this->tenantContext);
        $this->checklists = new InMemoryChecklists($this->rounds, $this->tenantContext);
        $this->events = new RecordingReviewEvents();
        $this->pingClearing = new InMemoryPingClearing();
        $this->ticketTurnQueries = new InMemoryTicketTurnQueries([self::ticketAssignedTo(self::TICKET, self::OMAR), self::ticketAssignedTo(self::OTHER_TICKET, null)], new TicketId(self::BROKEN_TICKET));
        $this->commands = new UseCaseChecklistCommands(
            $this->checklists,
            $this->rounds,
            new InMemoryChecklistQueries($this->checklists, $this->rounds, $this->tenantContext),
            $this->events,
            $this->pingClearing,
            $this->tenantContext,
            $this->ticketTurnQueries,
        );

        $this->tenantContext->set(new OrganizationId(self::BETA_BAKERY));
        $this->addReport(self::BETA_BAKERY, self::BETA_TICKET, true);
        $this->commands->createFromReport(new TicketId(self::BETA_TICKET), new \DateTimeImmutable(self::CREATED_AT));
        $this->rounds->numberedTicketIds = [];
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->addReport(self::ACME_FOODS, self::TICKET, true);
        $this->addReport(self::ACME_FOODS, self::TICKET_WITHOUT_PINS, false);

        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        foreach ([self::OMAR, self::MIA] as $userId) {
            $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::TICKET), new UserId($userId), 2);
            $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::OTHER_TICKET), new UserId($userId), 1);
        }
    }

    #[Test]
    public function theApiStatesAreTheDomainStates(): void
    {
        self::assertEquals(
            [ChecklistCommands::STATE_OPEN, ChecklistCommands::STATE_FIXED, ChecklistCommands::STATE_OK, ChecklistCommands::STATE_NOT_FIXED],
            array_map(static fn (CheckItemState $state): string => $state->value, CheckItemState::cases()),
        );
    }

    #[Test]
    public function theChecklistIsMadeFromRoundZerosPinsSharedWithNoNumberAndOwnedByTheReporter(): void
    {
        $roundId = $this->commands->createFromReport(new TicketId(self::TICKET), new \DateTimeImmutable(self::CREATED_AT));

        $checklist = $this->storedRound($roundId);
        self::assertSame(
            [RoundKind::Checklist, null, self::CREATED_AT, self::LENA],
            [$checklist->kind(), $checklist->number(), $checklist->sharedAt()?->format('Y-m-d H:i:sP'), $checklist->ownerId()->toString()],
        );
        $items = $this->acmeItems();
        self::assertCount(3, $items);
        foreach ($items as $item) {
            self::assertSame([$roundId, CheckItemState::Open], [$item->roundId()->toString(), $item->state()]);
        }
        self::assertSame([], $this->rounds->numberedTicketIds, 'The checklist takes no round number, so it reads none.');
        self::assertSame([], $this->events->published());
    }

    #[Test]
    public function theFirstRoundSharedAfterTheChecklistIsRoundOneAndTheNextIsRoundTwo(): void
    {
        $this->commands->createFromReport(new TicketId(self::TICKET), new \DateTimeImmutable(self::CREATED_AT));

        self::assertSame(1, $this->rounds->nextFreeNumberOf(new TicketId(self::TICKET)), 'Only Round 0 and the checklist exist, so a share takes 1.');
        $this->rounds->add($this->sharedDraftOf(self::OMAR, $this->rounds->nextFreeNumberOf(new TicketId(self::TICKET))));
        $this->rounds->add($this->sharedDraftOf(self::MIA, $this->rounds->nextFreeNumberOf(new TicketId(self::TICKET))));

        self::assertSame(
            [[RoundKind::Report, 0], [RoundKind::Checklist, null], [RoundKind::Draft, 1], [RoundKind::Draft, 2]],
            array_map(
                static fn (Round $round): array => [$round->kind(), $round->number()],
                array_values(array_filter($this->rounds->stored, static fn (Round $round): bool => self::TICKET === $round->ticketId()->toString())),
            ),
        );
    }

    #[Test]
    public function aChecklistMadeAfterARoundTheTicketHasSharedTakesNoNumberEither(): void
    {
        $this->rounds->add($this->sharedDraftOf(self::OMAR, 1));

        $roundId = $this->commands->createFromReport(new TicketId(self::TICKET), new \DateTimeImmutable(self::CREATED_AT));

        self::assertNull($this->storedRound($roundId)->number());
        self::assertSame(2, $this->rounds->nextFreeNumberOf(new TicketId(self::TICKET)));
    }

    #[Test]
    public function aSecondChecklistOfTheTicketIsNoneAndWritesNothing(): void
    {
        $this->commands->createFromReport(new TicketId(self::TICKET), new \DateTimeImmutable(self::CREATED_AT));
        $roundsBefore = $this->rounds->stored;
        $itemsBefore = $this->checklists->storedItems;

        $secondRoundId = $this->commands->createFromReport(new TicketId(self::TICKET), new \DateTimeImmutable(self::NOW));

        self::assertNull($secondRoundId);
        self::assertEquals([$roundsBefore, $itemsBefore], [$this->rounds->stored, $this->checklists->storedItems]);
        self::assertSame([], $this->events->published());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ticketsWithNothingToCheck(): iterable
    {
        yield 'a Round 0 with no pins' => [self::TICKET_WITHOUT_PINS];
        yield 'a ticket with no Round 0' => [self::TICKET_WITHOUT_REPORT];
        yield 'a ticket only Beta Bakery has' => [self::BETA_TICKET];
    }

    #[Test]
    #[DataProvider('ticketsWithNothingToCheck')]
    public function aTicketWithNothingToCheckGetsNoChecklist(string $ticketId): void
    {
        $roundsBefore = $this->rounds->stored;
        $itemsBefore = $this->checklists->storedItems;

        self::assertNull($this->commands->createFromReport(new TicketId($ticketId), new \DateTimeImmutable(self::CREATED_AT)));

        self::assertEquals([$roundsBefore, $itemsBefore], [$this->rounds->stored, $this->checklists->storedItems]);
    }

    #[Test]
    public function omarMarksTheSecondPinFixedAndHisPingsOnTheTicketAreCleared(): void
    {
        $item = $this->secondItemOfAcmeChecklist();

        $change = $this->commands->markFixed($item->id()->toString(), new UserId(self::OMAR), new \DateTimeImmutable(self::NOW));

        self::assertEquals(new CheckItemChange(2, ChecklistCommands::STATE_FIXED, 2), $change);
        $stored = $this->storedItem($item);
        self::assertSame([CheckItemState::Fixed, self::OMAR, self::NOW], [$stored->state(), $stored->changedBy()?->toString(), $stored->changedAt()?->format('Y-m-d H:i:sP')]);
        self::assertSame([$item->id()->toString()], $this->checklists->lockedItemIds);
        self::assertEquals(
            [new ChecklistItemChanged(self::ACME_FOODS, self::TICKET, $item->roundId()->toString(), $item->id()->toString(), 'fixed', self::OMAR, new \DateTimeImmutable(self::NOW))],
            $this->events->published(),
        );
        self::assertEquals([[self::ACME_FOODS, self::TICKET, self::OMAR, new \DateTimeImmutable(self::NOW)]], $this->pingClearing->calls());
        // Only Omar's pings on SHOP-1 went: a second clear finds Mia's and Omar's other ticket's still open.
        self::assertSame(
            [0, 2, 1],
            [
                $this->pingClearing->clearForActor(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::OMAR), new \DateTimeImmutable(self::NOW)),
                $this->pingClearing->clearForActor(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::MIA), new \DateTimeImmutable(self::NOW)),
                $this->pingClearing->clearForActor(new OrganizationId(self::ACME_FOODS), new TicketId(self::OTHER_TICKET), new UserId(self::OMAR), new \DateTimeImmutable(self::NOW)),
            ],
        );
    }

    #[Test]
    public function miaConfirmsAFixedPinOk(): void
    {
        $item = $this->secondItemOfAcmeChecklist();
        $this->moveStoredItem($item, static fn (CheckItem $read) => $read->markFixed(new UserId(self::OMAR), new \DateTimeImmutable(self::CREATED_AT)));

        $change = $this->commands->markOk($item->id()->toString(), new UserId(self::MIA), new \DateTimeImmutable(self::NOW));

        self::assertEquals(new CheckItemChange(2, ChecklistCommands::STATE_OK, 2), $change);
        self::assertSame([CheckItemState::Ok, self::MIA], [$this->storedItem($item)->state(), $this->storedItem($item)->changedBy()?->toString()]);
        self::assertEquals(
            [new ChecklistItemChanged(self::ACME_FOODS, self::TICKET, $item->roundId()->toString(), $item->id()->toString(), 'ok', self::MIA, new \DateTimeImmutable(self::NOW))],
            $this->events->published(),
        );
    }

    #[Test]
    public function miaSendsAFixedPinBackAsNotFixedAndOmarCanMarkItFixedAgain(): void
    {
        $item = $this->secondItemOfAcmeChecklist();
        $this->moveStoredItem($item, static fn (CheckItem $read) => $read->markFixed(new UserId(self::OMAR), new \DateTimeImmutable(self::CREATED_AT)));

        $sentBack = $this->commands->markNotFixed($item->id()->toString(), new UserId(self::MIA), new \DateTimeImmutable(self::NOW));
        $fixedAgain = $this->commands->markFixed($item->id()->toString(), new UserId(self::OMAR), new \DateTimeImmutable(self::NOW));

        self::assertEquals([new CheckItemChange(2, ChecklistCommands::STATE_NOT_FIXED, 2), new CheckItemChange(2, ChecklistCommands::STATE_FIXED, 2)], [$sentBack, $fixedAgain]);
        self::assertSame(['not_fixed', 'fixed'], array_map(static fn (object $event): string => $event instanceof ChecklistItemChanged ? $event->state : '', $this->events->published()));
    }

    #[Test]
    public function anIdInUpperCaseNamesTheSameItem(): void
    {
        $item = $this->secondItemOfAcmeChecklist();

        $this->commands->markFixed(strtoupper($item->id()->toString()), new UserId(self::OMAR), new \DateTimeImmutable(self::NOW));

        self::assertSame(CheckItemState::Fixed, $this->storedItem($item)->state());
    }

    /**
     * The command, the state the item is put in first (null leaves it open), and the reason.
     *
     * @return iterable<string, array{string, CheckItemState|null, string}>
     */
    public static function refusedMoves(): iterable
    {
        yield 'marking fixed an item marked fixed' => ['markFixed', CheckItemState::Fixed, 'This check is marked fixed already.'];
        yield 'marking fixed an item confirmed OK' => ['markFixed', CheckItemState::Ok, 'This check is confirmed OK, so it no longer changes.'];
        yield 'confirming an open item OK' => ['markOk', null, 'Only a check marked fixed can be confirmed OK.'];
        yield 'confirming a not-fixed item OK' => ['markOk', CheckItemState::NotFixed, 'Only a check marked fixed can be confirmed OK.'];
        yield 'confirming an OK item OK again' => ['markOk', CheckItemState::Ok, 'Only a check marked fixed can be confirmed OK.'];
        yield 'sending back an open item' => ['markNotFixed', null, 'Only a check marked fixed can be sent back as not fixed.'];
        yield 'sending back an OK item' => ['markNotFixed', CheckItemState::Ok, 'Only a check marked fixed can be sent back as not fixed.'];
    }

    #[Test]
    #[DataProvider('refusedMoves')]
    public function aMoveTheItemsStateRefusesChangesNothing(string $command, ?CheckItemState $stateFirst, string $reason): void
    {
        $item = $this->secondItemOfAcmeChecklist();
        if (null !== $stateFirst) {
            $this->moveStoredItem($item, static fn (CheckItem $read) => $read->markFixed(new UserId(self::OMAR), new \DateTimeImmutable(self::CREATED_AT)));
        }
        if (CheckItemState::Ok === $stateFirst) {
            $this->moveStoredItem($item, static fn (CheckItem $read) => $read->markOk(new UserId(self::MIA), new \DateTimeImmutable(self::CREATED_AT)));
        }
        if (CheckItemState::NotFixed === $stateFirst) {
            $this->moveStoredItem($item, static fn (CheckItem $read) => $read->markNotFixed(new UserId(self::MIA), new \DateTimeImmutable(self::CREATED_AT)));
        }

        $refusal = $this->refusalOf($command, $item->id()->toString());

        self::assertSame($reason, $refusal->getMessage());
        self::assertFalse($refusal->isNotFound());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function itemsTheTenantHasNot(): iterable
    {
        yield 'a malformed id' => ['not-an-item'];
        yield 'an empty id' => [''];
        yield 'an unknown id' => [self::UNKNOWN_ITEM];
    }

    #[Test]
    public function theIdOfAnItemWithATrailingNewlineIsNotFound(): void
    {
        $item = $this->secondItemOfAcmeChecklist();

        self::assertSame(ChecklistRefused::NOT_FOUND, $this->refusalOf('markFixed', $item->id()->toString()."\n")->getMessage());
        self::assertSame(CheckItemState::Open, $this->storedItem($item)->state());
    }

    #[Test]
    #[DataProvider('itemsTheTenantHasNot')]
    public function anItemTheTenantHasNotIsNotFoundForEveryCommand(string $itemId): void
    {
        foreach (['markFixed', 'markOk', 'markNotFixed'] as $command) {
            $refusal = $this->refusalOf($command, $itemId);

            self::assertSame(ChecklistRefused::NOT_FOUND, $refusal->getMessage(), $command);
            self::assertTrue($refusal->isNotFound());
        }
    }

    #[Test]
    public function anotherOrganizationsItemIsNotFoundAndStaysAsItWas(): void
    {
        $betaItemIds = [];
        foreach ($this->checklists->storedItems as $itemId => [$organizationId]) {
            if ($organizationId->equals(new OrganizationId(self::BETA_BAKERY))) {
                $betaItemIds[] = $itemId;
            }
        }
        self::assertNotEmpty($betaItemIds);

        foreach ($betaItemIds as $betaItemId) {
            self::assertSame(ChecklistRefused::NOT_FOUND, $this->refusalOf('markFixed', $betaItemId)->getMessage());
        }
    }

    #[Test]
    public function withNoTenantNothingIsRead(): void
    {
        $item = $this->secondItemOfAcmeChecklist();
        $this->tenantContext->clear();
        $itemsBefore = $this->checklists->storedItems;

        try {
            $this->commands->markFixed($item->id()->toString(), new UserId(self::OMAR), new \DateTimeImmutable(self::NOW));
            self::fail('A change with no tenant was not refused.');
        } catch (TenantNotSet) {
        }

        self::assertEquals($itemsBefore, $this->checklists->storedItems);
        self::assertSame([[], [], []], [$this->checklists->lockedItemIds, $this->events->published(), $this->pingClearing->calls()]);
    }

    #[Test]
    public function miasSharedFindingsGetAnOpenItemPerPinOnTheirOwnRoundInPictureAndPinOrder(): void
    {
        $roundId = $this->addSharedDraft(self::MIA, self::TICKET, true);
        $roundsBefore = $this->rounds->stored;
        $loadedRoundsBefore = $this->rounds->loadedRounds;

        $made = $this->commands->createFromFindings($this->storedRound($roundId), new \DateTimeImmutable(self::NOW));

        self::assertSame(3, $made);
        $items = $this->acmeItems();
        self::assertSame(
            [[$roundId, 'Cut off', CheckItemState::Open], [$roundId, 'Shown twice', CheckItemState::Open], [$roundId, 'Wrong colour', CheckItemState::Open]],
            array_map(fn (CheckItem $item): array => [$item->roundId()->toString(), $this->noteOfPin($roundId, $item), $item->state()], $items),
        );
        foreach ($items as $item) {
            self::assertSame([self::TICKET, null, null], [$item->ticketId()->toString(), $item->changedBy(), $item->changedAt()]);
        }
        self::assertEquals($roundsBefore, $this->rounds->stored, 'Findings add no round of their own.');
        self::assertSame([[], []], [$this->rounds->numberedTicketIds, $this->events->published()]);
        self::assertSame($loadedRoundsBefore, $this->rounds->loadedRounds, 'The round the share saved is not read again.');
        self::assertSame([self::TICKET], $this->ticketTurnQueries->askedTicketIds, 'The ticket is read once, for its assignee.');
    }

    #[Test]
    public function omarsSharedDraftIsASelfCheckAndGetsNoItems(): void
    {
        $roundId = $this->addSharedDraft(self::OMAR, self::TICKET, true);

        self::assertSame(0, $this->commands->createFromFindings($this->storedRound($roundId), new \DateTimeImmutable(self::NOW)));

        self::assertSame([], $this->acmeItems());
    }

    #[Test]
    public function onATicketWithNoAssigneeEvenOmarsSharedDraftIsFindings(): void
    {
        $roundId = $this->addSharedDraft(self::OMAR, self::OTHER_TICKET, true);

        self::assertSame(3, $this->commands->createFromFindings($this->storedRound($roundId), new \DateTimeImmutable(self::NOW)));

        self::assertCount(3, $this->acmeItems());
    }

    /**
     * Nothing to check whoever shared it, so the ticket is not read: on the broken ticket a read would throw.
     */
    #[Test]
    public function sharedFindingsWithoutPinsGetNoItemsAndReadNoTicket(): void
    {
        $roundId = $this->addSharedDraft(self::MIA, self::BROKEN_TICKET, false);

        self::assertSame(0, $this->commands->createFromFindings($this->storedRound($roundId), new \DateTimeImmutable(self::NOW)));

        self::assertSame([[], []], [$this->acmeItems(), $this->ticketTurnQueries->askedTicketIds]);
    }

    #[Test]
    public function aSecondCallForTheSameFindingsIsABugAndWritesNothing(): void
    {
        $round = $this->storedRound($this->addSharedDraft(self::MIA, self::TICKET, true));
        $this->commands->createFromFindings($round, new \DateTimeImmutable(self::NOW));
        $itemsBefore = $this->checklists->storedItems;

        try {
            $this->commands->createFromFindings($round, new \DateTimeImmutable(self::NOW));
            self::fail('A second call made items again.');
        } catch (\LogicException $refused) {
            self::assertSame(InMemoryChecklists::ITEM_REFUSED, $refused->getMessage());
        }

        self::assertEquals($itemsBefore, $this->checklists->storedItems);
    }

    /**
     * @return iterable<string, array{\Closure(self): string, string}>
     */
    public static function roundsThatAreNotSharedFindings(): iterable
    {
        yield 'an unshared draft' => [static fn (self $test): string => $test->addUnsharedDraft(), 'Only a shared draft round has findings to check.'];
        yield 'Round 0' => [static fn (self $test): string => $test->reportRoundId(), 'Only a shared draft round has findings to check.'];
        yield 'a round of a ticket that cannot be read' => [static fn (self $test): string => $test->addSharedDraft(self::MIA, self::BROKEN_TICKET, true), 'A ticket is in a step its organization does not have.'];
    }

    /**
     * @param \Closure(self): string $roundIdOf
     */
    #[Test]
    #[DataProvider('roundsThatAreNotSharedFindings')]
    public function aRoundThatIsNotSharedFindingsIsABugAndGetsNoItems(\Closure $roundIdOf, string $reason): void
    {
        $round = $this->storedRound($roundIdOf($this));

        try {
            $this->commands->createFromFindings($round, new \DateTimeImmutable(self::NOW));
            self::fail('Items were made for a round that is not shared findings.');
        } catch (\LogicException $refused) {
            self::assertSame($reason, $refused->getMessage());
        }

        self::assertSame([], $this->acmeItems());
    }

    #[Test]
    public function findingsOfATicketWhoseFactsAreMissingFailClosedAndGetNoItems(): void
    {
        $roundId = $this->addSharedDraft(self::MIA, self::TICKET_WITHOUT_PINS, true);

        try {
            $this->commands->createFromFindings($this->storedRound($roundId), new \DateTimeImmutable(self::NOW));
            self::fail('Findings were made without knowing the ticket\'s assignee.');
        } catch (\LogicException $refused) {
            self::assertSame(\sprintf('The ticket of round %s cannot be read.', $roundId), $refused->getMessage());
        }

        self::assertSame([], $this->acmeItems());
    }

    #[Test]
    public function anotherOrganizationsSharedFindingsAreNotTheTenants(): void
    {
        $round = $this->storedRound($this->addSharedDraft(self::MIA, self::TICKET, true));
        $this->tenantContext->set(new OrganizationId(self::BETA_BAKERY));
        $betaItemsBefore = $this->checklists->storedItems;

        try {
            $this->commands->createFromFindings($round, new \DateTimeImmutable(self::NOW));
            self::fail('Beta Bakery made items on Acme Foods\' round.');
        } catch (\LogicException $refused) {
            self::assertSame(\sprintf('Round %s is not the tenant\'s.', $round->id()->toString()), $refused->getMessage());
        }

        self::assertEquals($betaItemsBefore, $this->checklists->storedItems);
        self::assertSame([], $this->ticketTurnQueries->askedTicketIds);
    }

    /**
     * Runs the command and proves the refusal left the items, events and pings as they were.
     */
    private function refusalOf(string $command, string $itemId): ChecklistRefused
    {
        $itemsBefore = $this->checklists->storedItems;
        $eventsBefore = $this->events->published();
        $pingCallsBefore = $this->pingClearing->calls();
        try {
            match ($command) {
                'markFixed' => $this->commands->markFixed($itemId, new UserId(self::OMAR), new \DateTimeImmutable(self::NOW)),
                'markOk' => $this->commands->markOk($itemId, new UserId(self::MIA), new \DateTimeImmutable(self::NOW)),
                'markNotFixed' => $this->commands->markNotFixed($itemId, new UserId(self::MIA), new \DateTimeImmutable(self::NOW)),
                default => throw new \LogicException('No such command.'),
            };
        } catch (ChecklistRefused $refusal) {
            self::assertEquals($itemsBefore, $this->checklists->storedItems, 'A refused change wrote an item.');
            self::assertSame($eventsBefore, $this->events->published(), 'A refused change published.');
            self::assertSame($pingCallsBefore, $this->pingClearing->calls(), 'A refused change cleared pings.');

            return $refusal;
        }

        self::fail(\sprintf('%s on %s was not refused.', $command, $itemId));
    }

    /**
     * Lena's Round 0 of the ticket: two pins on the first picture and one on the second, given out of order; with
     * $hasPins false, one picture and no pin.
     */
    private function addReport(string $organizationId, string $ticketId, bool $hasPins): void
    {
        $lena = new UserId(self::LENA);
        $screenshots = $hasPins
            ? [
                Screenshot::of($this->rounds->nextScreenshotIdentity(), self::MEDIA, 1, null, null, [
                    Pin::placedBy($this->rounds->nextPinIdentity(), 1, 10.0, 10.0, 'Logo too small', $lena),
                ]),
                Screenshot::of($this->rounds->nextScreenshotIdentity(), self::MEDIA, 0, null, null, [
                    Pin::placedBy($this->rounds->nextPinIdentity(), 2, 20.0, 20.0, 'Wrong total', $lena),
                    Pin::placedBy($this->rounds->nextPinIdentity(), 1, 30.0, 30.0, 'Missing price', $lena),
                ]),
            ]
            : [Screenshot::of($this->rounds->nextScreenshotIdentity(), self::MEDIA, 0, null, null, [])];
        $this->rounds->add(Round::report($this->rounds->nextIdentity(), new OrganizationId($organizationId), new TicketId($ticketId), $lena, $screenshots, new \DateTimeImmutable(self::REPORTED_AT)));
    }

    private function storedRound(?string $roundId): Round
    {
        self::assertNotNull($roundId);
        foreach ($this->rounds->stored as $round) {
            if ($round->id()->toString() === $roundId) {
                return $round;
            }
        }

        self::fail(\sprintf('Round %s is not stored.', $roundId));
    }

    /**
     * @return list<CheckItem> Acme Foods' items, in the order they were added
     */
    private function acmeItems(): array
    {
        $items = [];
        foreach ($this->checklists->storedItems as [$organizationId, $item]) {
            if ($organizationId->equals(new OrganizationId(self::ACME_FOODS))) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * The open item of pin 2 on the first picture, which reportPinsOf gives second.
     */
    private function secondItemOfAcmeChecklist(): CheckItem
    {
        $this->commands->createFromReport(new TicketId(self::TICKET), new \DateTimeImmutable(self::CREATED_AT));

        return $this->acmeItems()[1] ?? self::fail('The checklist has no second item.');
    }

    private function storedItem(CheckItem $item): CheckItem
    {
        return $this->checklists->storedItems[$item->id()->toString()][1] ?? self::fail('The item is not stored.');
    }

    /**
     * @param \Closure(CheckItem): void $move
     */
    private function moveStoredItem(CheckItem $item, \Closure $move): void
    {
        $read = $this->checklists->lockedItem($item->id()) ?? self::fail('The item is not the tenant\'s.');
        $move($read);
        $this->checklists->saveItem($read);
        $this->checklists->lockedItemIds = [];
    }

    /**
     * The owner's draft of the ticket in Acme Foods, shared as Round 1, as the checklist has no number: with $hasPins, pins 2 and 1 on its first
     * picture, given out of order, and pin 1 on its second; else one picture without pins.
     */
    private function addSharedDraft(string $ownerId, string $ticketId, bool $hasPins): string
    {
        $owner = new UserId($ownerId);
        $draft = Round::draft($this->rounds->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId($ticketId), $owner, new \DateTimeImmutable(self::CREATED_AT));
        $first = $this->rounds->nextScreenshotIdentity();
        $draft->addScreenshot($owner, $first, self::MEDIA);
        if ($hasPins) {
            $second = $this->rounds->nextScreenshotIdentity();
            $draft->addScreenshot($owner, $second, self::MEDIA);
            $draft->replacePins($owner, $second, [Pin::placedBy($this->rounds->nextPinIdentity(), 1, 50.0, 50.0, 'Wrong colour', $owner)]);
            $draft->replacePins($owner, $first, [
                Pin::placedBy($this->rounds->nextPinIdentity(), 2, 20.0, 20.0, 'Shown twice', $owner),
                Pin::placedBy($this->rounds->nextPinIdentity(), 1, 10.0, 10.0, 'Cut off', $owner),
            ]);
        }
        $draft->share($owner, 1, new \DateTimeImmutable(self::CREATED_AT));
        $this->rounds->add($draft);

        return $draft->id()->toString();
    }

    /**
     * The owner's draft of TICKET with one picture and no pins, shared as round $number.
     */
    private function sharedDraftOf(string $ownerId, int $number): Round
    {
        $owner = new UserId($ownerId);
        $draft = Round::draft($this->rounds->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), $owner, new \DateTimeImmutable(self::REPORTED_AT));
        $draft->addScreenshot($owner, $this->rounds->nextScreenshotIdentity(), self::MEDIA);
        $draft->share($owner, $number, new \DateTimeImmutable(self::REPORTED_AT));

        return $draft;
    }

    private function addUnsharedDraft(): string
    {
        $mia = new UserId(self::MIA);
        $draft = Round::draft($this->rounds->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), $mia, new \DateTimeImmutable(self::CREATED_AT));
        $screenshotId = $this->rounds->nextScreenshotIdentity();
        $draft->addScreenshot($mia, $screenshotId, self::MEDIA);
        $draft->replacePins($mia, $screenshotId, [Pin::placedBy($this->rounds->nextPinIdentity(), 1, 10.0, 10.0, 'Cut off', $mia)]);
        $this->rounds->add($draft);

        return $draft->id()->toString();
    }

    private function reportRoundId(): string
    {
        foreach ($this->rounds->stored as $round) {
            if (Round::REPORT_NUMBER === $round->number() && self::TICKET === $round->ticketId()->toString()) {
                return $round->id()->toString();
            }
        }

        self::fail('SHOP-1 has no Round 0.');
    }

    private function noteOfPin(string $roundId, CheckItem $item): string
    {
        foreach ($this->storedRound($roundId)->screenshots() as $screenshot) {
            foreach ($screenshot->pins() as $pin) {
                if ($pin->id()->equals($item->pinId())) {
                    return $pin->note();
                }
            }
        }

        self::fail('The item names a pin its round does not have.');
    }

    private static function ticketAssignedTo(string $ticketId, ?string $assigneeId): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId($ticketId),
            '0197a3c0-0000-7000-8000-000000007a41',
            '0197a3c0-0000-7000-8000-000000007a42',
            'test',
            'check',
            null,
            null,
            null,
            [],
            [],
            'all',
            'normal',
            null,
            new UserId(self::LENA),
            null === $assigneeId ? null : new UserId($assigneeId),
            testerId: new UserId(self::MIA),
        );
    }
}

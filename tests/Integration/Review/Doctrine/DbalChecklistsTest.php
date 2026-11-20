<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use App\Review\Domain\CheckItem;
use App\Review\Domain\CheckItemId;
use App\Review\Domain\CheckItemState;
use App\Review\Domain\Checklist;
use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\ReportPins;
use App\Review\Domain\Round;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundSlotTaken;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Infrastructure\Doctrine\DbalChecklists;
use App\Review\Infrastructure\Doctrine\DbalRounds;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * DbalChecklists on the real rvw_round and rvw_check_item tables as pinround_app, under row-level security. Every time
 * is a fixed one in March 2027; nothing reads the clock. Each test commits its rows, so a refusal is proven against
 * rows another transaction wrote, and removes them through the migrator.
 */
final class DbalChecklistsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f5301';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000f5302';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f5303';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f5304';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f5311';
    private const string TICKET_WITHOUT_ROUNDS = '0197a3c0-0000-7000-8000-0000000f5312';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f5321';
    private const string OTHER_ORGANIZATION_REPORT = '0197a3c0-0000-7000-8000-0000000f5322';
    private const string CHECKLIST = '0197a3c0-0000-7000-8000-0000000f5323';
    private const string SECOND_CHECKLIST = '0197a3c0-0000-7000-8000-0000000f5324';
    private const string MIA_DRAFT = '0197a3c0-0000-7000-8000-0000000f5325';
    private const string FIRST_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5331';
    private const string SECOND_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5332';
    private const string OTHER_ORGANIZATION_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5333';
    private const string MIA_DRAFT_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5334';
    private const string FIRST_PIN = '0197a3c0-0000-7000-8000-0000000f5341';
    private const string SECOND_PIN = '0197a3c0-0000-7000-8000-0000000f5342';
    private const string THIRD_PIN = '0197a3c0-0000-7000-8000-0000000f5343';
    private const string OTHER_ORGANIZATION_PIN = '0197a3c0-0000-7000-8000-0000000f5344';
    private const string FIRST_ITEM = '0197a3c0-0000-7000-8000-0000000f5351';
    private const string SECOND_ITEM = '0197a3c0-0000-7000-8000-0000000f5352';
    private const string THIRD_ITEM = '0197a3c0-0000-7000-8000-0000000f5353';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f5361';
    // An offset other than UTC, so a time written without its offset would read back an hour off.
    private const string CREATED_AT = '2027-03-16 11:00:00+01:00';
    private const string CREATED_AT_UTC = '2027-03-16 10:00:00';
    private const string MARKED_AT = '2027-03-17 09:15:00+01:00';
    private const string MARKED_AT_UTC = '2027-03-17 08:15:00';

    private ?EntityManagerInterface $otherSession = null;

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        $this->transactions()->committedAs(self::OTHER_ORGANIZATION, function (): void {
            $this->rounds()->add(Round::report(new RoundId(self::OTHER_ORGANIZATION_REPORT), new OrganizationId(self::OTHER_ORGANIZATION), new TicketId(self::TICKET), new UserId(self::MIA), [
                Screenshot::of(new ScreenshotId(self::OTHER_ORGANIZATION_SCREENSHOT), self::MEDIA, 0, null, null, [self::pin(self::OTHER_ORGANIZATION_PIN, 1)]),
            ], new \DateTimeImmutable(self::CREATED_AT)));
        });
        // Pictures and pins given out of order, so the order reportPinsOf gives comes from the positions and numbers.
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->rounds()->add(Round::report(new RoundId(self::REPORT), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), [
                Screenshot::of(new ScreenshotId(self::SECOND_SCREENSHOT), self::MEDIA, 1, null, null, [self::pin(self::THIRD_PIN, 1)]),
                Screenshot::of(new ScreenshotId(self::FIRST_SCREENSHOT), self::MEDIA, 0, null, null, [self::pin(self::SECOND_PIN, 2), self::pin(self::FIRST_PIN, 1)]),
            ], new \DateTimeImmutable(self::CREATED_AT)));
        });
    }

    protected function tearDown(): void
    {
        $this->otherSession?->getConnection()->close();
        $this->otherSession = null;
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function theReportPinsComeByPicturePositionThenPinNumberAndOnlyTheTenantsOnes(): void
    {
        [$reportPins, $noReport] = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->checklists()->reportPinsOf(new TicketId(self::TICKET)),
            $this->checklists()->reportPinsOf(new TicketId(self::TICKET_WITHOUT_ROUNDS)),
        ]);

        self::assertNotNull($reportPins);
        self::assertSame(
            [self::LENA, [self::FIRST_PIN, self::SECOND_PIN, self::THIRD_PIN]],
            [$reportPins->reporterId()->toString(), array_map(static fn (PinId $pinId): string => $pinId->toString(), $reportPins->pinIds())],
        );
        self::assertNull($noReport);
    }

    #[Test]
    public function aChecklistIsWrittenAsASharedRoundWithAnOpenItemPerPin(): void
    {
        $this->addCommitted(self::checklist(self::CHECKLIST, 1));

        self::assertSame(
            [[
                'id' => self::CHECKLIST, 'ticket_id' => self::TICKET, 'number' => 1, 'kind' => 'checklist', 'owner_id' => self::LENA,
                'shared_at_utc' => self::CREATED_AT_UTC, 'state' => 'open', 'version' => 1, 'created_at_utc' => self::CREATED_AT_UTC,
            ]],
            $this->rowsAs(self::ACME_FOODS, "SELECT id, ticket_id, number, kind, owner_id, shared_at AT TIME ZONE 'UTC' AS shared_at_utc, state, version, created_at AT TIME ZONE 'UTC' AS created_at_utc FROM rvw_round WHERE kind = 'checklist'"),
        );
        self::assertSame(
            [
                ['id' => self::FIRST_ITEM, 'round_id' => self::CHECKLIST, 'pin_id' => self::FIRST_PIN, 'state' => 'open', 'changed_by' => null, 'changed_at' => null, 'created_at_utc' => self::CREATED_AT_UTC],
                ['id' => self::SECOND_ITEM, 'round_id' => self::CHECKLIST, 'pin_id' => self::SECOND_PIN, 'state' => 'open', 'changed_by' => null, 'changed_at' => null, 'created_at_utc' => self::CREATED_AT_UTC],
                ['id' => self::THIRD_ITEM, 'round_id' => self::CHECKLIST, 'pin_id' => self::THIRD_PIN, 'state' => 'open', 'changed_by' => null, 'changed_at' => null, 'created_at_utc' => self::CREATED_AT_UTC],
            ],
            $this->rowsAs(self::ACME_FOODS, "SELECT id, round_id, pin_id, state, changed_by, changed_at, created_at AT TIME ZONE 'UTC' AS created_at_utc FROM rvw_check_item ORDER BY id"),
        );
        self::assertSame([], $this->rowsAs(self::OTHER_ORGANIZATION, 'SELECT id FROM rvw_check_item'), 'Another organization sees the checklist items.');
        self::assertSame(2, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int => $this->rounds()->nextFreeNumberOf(new TicketId(self::TICKET))));
    }

    /**
     * Two requests that both found no checklist both add one; the second is refused by the ticket's checklist slot
     * even under another number, writes nothing, and the caller's transaction goes on (DbalChecklists::add's savepoint).
     */
    #[Test]
    public function aSecondChecklistOfTheTicketIsASlotTakenAndWritesNothing(): void
    {
        $this->addCommitted(self::checklist(self::CHECKLIST, 1));

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?RoundSlotTaken {
            try {
                $this->checklists()->add(self::checklist(self::SECOND_CHECKLIST, 2, ['0197a3c0-0000-7000-8000-0000000f5354', '0197a3c0-0000-7000-8000-0000000f5355', '0197a3c0-0000-7000-8000-0000000f5356']));
            } catch (RoundSlotTaken $refusal) {
                $this->rounds()->add(Round::draft(new RoundId(self::MIA_DRAFT), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::MIA), new \DateTimeImmutable(self::CREATED_AT)));

                return $refusal;
            }

            return null;
        });

        self::assertInstanceOf(RoundSlotTaken::class, $refusal, 'The second checklist was not refused, so nothing below is proven.');
        self::assertSame('The ticket has this round already.', $refusal->getMessage());
        self::assertSame(
            [[self::REPORT, 'report'], [self::CHECKLIST, 'checklist'], [self::MIA_DRAFT, 'draft']],
            $this->numericRowsAs(self::ACME_FOODS, 'SELECT id, kind FROM rvw_round ORDER BY id'),
        );
        self::assertSame([[self::FIRST_ITEM], [self::SECOND_ITEM], [self::THIRD_ITEM]], $this->numericRowsAs(self::ACME_FOODS, 'SELECT id FROM rvw_check_item ORDER BY id'));
    }

    /**
     * Round 0 is deleted while its checklist round is still there: its pins go, and the items on them with the pins.
     */
    #[Test]
    public function deletingRound0FirstTakesTheChecklistItemsOfItsPinsAndNothingElse(): void
    {
        $this->addCommitted(self::checklist(self::CHECKLIST, 1));
        $itemsBefore = $this->roundRows()->runAs(self::ACME_FOODS, 'SELECT id FROM rvw_check_item');
        self::assertCount(3, $itemsBefore, 'No items to delete, so nothing below is proven.');

        $deleted = $this->roundRows()->runAs(self::ACME_FOODS, 'DELETE FROM rvw_round WHERE id = ? RETURNING id', [self::REPORT]);

        self::assertSame([[self::REPORT]], $deleted);
        self::assertSame([], $this->roundRows()->runAs(self::ACME_FOODS, 'SELECT id FROM rvw_check_item'));
        self::assertSame([], $this->roundRows()->runAs(self::ACME_FOODS, 'SELECT id FROM rvw_pin'));
        self::assertSame([[self::CHECKLIST]], $this->roundRows()->runAs(self::ACME_FOODS, 'SELECT id FROM rvw_round'), 'The checklist round must stay; only its items go.');
        self::assertCount(1, $this->roundRows()->runAs(self::OTHER_ORGANIZATION, 'SELECT id FROM rvw_pin'), 'Another organization lost a pin.');
    }

    #[Test]
    public function aChecklistOnANumberTheTicketHasIsASlotTakenAndWritesNothing(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $draft = Round::draft(new RoundId(self::MIA_DRAFT), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::MIA), new \DateTimeImmutable(self::CREATED_AT));
            $draft->addScreenshot(new UserId(self::MIA), new ScreenshotId(self::MIA_DRAFT_SCREENSHOT), self::MEDIA);
            $draft->share(new UserId(self::MIA), 1, new \DateTimeImmutable(self::CREATED_AT));
            $this->rounds()->add($draft);
        });

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?RoundSlotTaken {
            try {
                $this->checklists()->add(self::checklist(self::CHECKLIST, 1));
            } catch (RoundSlotTaken $refusal) {
                return $refusal;
            }

            return null;
        });

        self::assertInstanceOf(RoundSlotTaken::class, $refusal);
        self::assertSame([[self::REPORT], [self::MIA_DRAFT]], $this->numericRowsAs(self::ACME_FOODS, 'SELECT id FROM rvw_round ORDER BY id'));
        self::assertSame([], $this->numericRowsAs(self::ACME_FOODS, 'SELECT id FROM rvw_check_item'));
    }

    /** A redelivered message adds the same checklist again: a bug to see, never a slot to tell the person about. */
    #[Test]
    public function theSameChecklistAddedAgainIsALogicExceptionAndNotASlot(): void
    {
        $this->addCommitted(self::checklist(self::CHECKLIST, 1));

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?\LogicException {
            try {
                $this->checklists()->add(self::checklist(self::CHECKLIST, 1));
            } catch (\LogicException $refusal) {
                return $refusal;
            }

            return null;
        });

        self::assertNotNull($refusal);
        self::assertNotInstanceOf(RoundSlotTaken::class, $refusal);
        self::assertSame(\sprintf('Round %s is added already; a round is added once.', self::CHECKLIST), $refusal->getMessage());
        self::assertSame([[self::FIRST_ITEM], [self::SECOND_ITEM], [self::THIRD_ITEM]], $this->numericRowsAs(self::ACME_FOODS, 'SELECT id FROM rvw_check_item ORDER BY id'));
    }

    /**
     * An item on a pin that is not stored is refused by the database after the round row went in; the savepoint takes
     * the round back out and the caller's transaction goes on.
     */
    #[Test]
    public function anItemOnAPinNotStoredLeavesNoRoundAndTheCallersTransactionGoesOn(): void
    {
        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?\LogicException {
            $checklist = Checklist::fromReport(
                new RoundId(self::CHECKLIST),
                new OrganizationId(self::ACME_FOODS),
                new TicketId(self::TICKET),
                new ReportPins(new UserId(self::LENA), [new PinId(self::FIRST_PIN), new PinId(self::OTHER_ORGANIZATION_PIN)]),
                1,
                static fn (): CheckItemId => new CheckItemId(Uuid::v7()->toRfc4122()),
                new \DateTimeImmutable(self::CREATED_AT),
            ) ?? throw new \LogicException('No pins.');
            try {
                $this->checklists()->add($checklist);
            } catch (\LogicException $refusal) {
                $this->rounds()->add(Round::draft(new RoundId(self::MIA_DRAFT), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::MIA), new \DateTimeImmutable(self::CREATED_AT)));

                return $refusal;
            }

            return null;
        });

        self::assertNotNull($refusal);
        self::assertSame('A checklist item has an id added before, or names a pin that is not stored or is in a checklist already.', $refusal->getMessage());
        self::assertInstanceOf(ForeignKeyConstraintViolationException::class, $refusal->getPrevious());
        self::assertSame([[self::REPORT], [self::MIA_DRAFT]], $this->numericRowsAs(self::ACME_FOODS, 'SELECT id FROM rvw_round ORDER BY id'));
        self::assertSame([], $this->numericRowsAs(self::ACME_FOODS, 'SELECT id FROM rvw_check_item'));
    }

    #[Test]
    public function addingReadingAndSavingNeedAnOpenTransaction(): void
    {
        $item = CheckItem::open(new CheckItemId(self::FIRST_ITEM), new RoundId(self::CHECKLIST), new TicketId(self::TICKET), new PinId(self::FIRST_PIN));
        $calls = [
            'add' => fn () => $this->checklists()->add(self::checklist(self::CHECKLIST, 1)),
            'reportPinsOf' => fn () => $this->checklists()->reportPinsOf(new TicketId(self::TICKET)),
            'lockedItem' => fn () => $this->checklists()->lockedItem(new CheckItemId(self::FIRST_ITEM)),
            'saveItem' => fn () => $this->checklists()->saveItem($item),
        ];
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));

        $refusals = [];
        foreach ($calls as $name => $call) {
            try {
                $call();
                $refusals[$name] = null;
            } catch (\LogicException $refusal) {
                $refusals[$name] = str_contains($refusal->getMessage(), 'none is open');
            }
        }

        self::assertSame(['add' => true, 'reportPinsOf' => true, 'lockedItem' => true, 'saveItem' => true], $refusals);
        self::assertSame([[self::REPORT]], $this->numericRowsAs(self::ACME_FOODS, 'SELECT id FROM rvw_round ORDER BY id'));
    }

    #[Test]
    public function aLockedItemSavedComesBackWithItsStateAndWhoChangedItWhen(): void
    {
        $this->addCommitted(self::checklist(self::CHECKLIST, 1));

        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $item = $this->checklists()->lockedItem(new CheckItemId(self::SECOND_ITEM)) ?? throw new \LogicException('No item.');
            $item->markFixed(new UserId(self::MIA), new \DateTimeImmutable(self::MARKED_AT));
            $this->checklists()->saveItem($item);
        });

        $item = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?CheckItem => $this->checklists()->lockedItem(new CheckItemId(self::SECOND_ITEM)));
        self::assertNotNull($item);
        self::assertSame(
            [self::SECOND_ITEM, self::CHECKLIST, self::TICKET, self::SECOND_PIN, CheckItemState::Fixed, CheckItemState::Fixed, self::MIA, self::MARKED_AT_UTC],
            [$item->id()->toString(), $item->roundId()->toString(), $item->ticketId()->toString(), $item->pinId()->toString(), $item->state(), $item->savedState(), $item->changedBy()?->toString(), $item->changedAt()?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
        self::assertSame(
            [[self::FIRST_ITEM, 'open'], [self::SECOND_ITEM, 'fixed'], [self::THIRD_ITEM, 'open']],
            $this->numericRowsAs(self::ACME_FOODS, 'SELECT id, state FROM rvw_check_item ORDER BY id'),
        );
    }

    #[Test]
    public function aSaveOverAStateTheItemNoLongerHasIsRefusedAndWritesNothing(): void
    {
        $this->addCommitted(self::checklist(self::CHECKLIST, 1));

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?\LogicException {
            $item = $this->checklists()->lockedItem(new CheckItemId(self::FIRST_ITEM)) ?? throw new \LogicException('No item.');
            $item->markFixed(new UserId(self::MIA), new \DateTimeImmutable(self::MARKED_AT));
            $this->checklists()->saveItem($item);
            $item->markOk(new UserId(self::LENA), new \DateTimeImmutable(self::MARKED_AT));
            try {
                $this->checklists()->saveItem($item);
            } catch (\LogicException $refusal) {
                return $refusal;
            }

            return null;
        });

        self::assertInstanceOf(\LogicException::class, $refusal);
        self::assertStringContainsString('changed since it was read', $refusal->getMessage());
        self::assertSame([['fixed', self::MIA]], $this->numericRowsAs(self::ACME_FOODS, 'SELECT state, changed_by FROM rvw_check_item WHERE id = ?', [self::FIRST_ITEM]));
    }

    #[Test]
    public function anotherOrganizationNeitherLocksNorSavesTheItem(): void
    {
        $this->addCommitted(self::checklist(self::CHECKLIST, 1));
        $item = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?CheckItem => $this->checklists()->lockedItem(new CheckItemId(self::FIRST_ITEM)));
        self::assertNotNull($item);
        $item->markFixed(new UserId(self::MIA), new \DateTimeImmutable(self::MARKED_AT));

        [$lockedByOther, $refusal] = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, function () use ($item): array {
            $lockedByOther = $this->checklists()->lockedItem(new CheckItemId(self::FIRST_ITEM));
            try {
                $this->checklists()->saveItem($item);
            } catch (\LogicException $refusal) {
                return [$lockedByOther, $refusal];
            }

            return [$lockedByOther, null];
        });

        self::assertNull($lockedByOther);
        self::assertInstanceOf(\LogicException::class, $refusal);
        self::assertStringContainsString('is not the tenant\'s', $refusal->getMessage());
        self::assertSame([['open', null]], $this->numericRowsAs(self::ACME_FOODS, 'SELECT state, changed_by FROM rvw_check_item WHERE id = ?', [self::FIRST_ITEM]));
    }

    /**
     * lockedItem holds the item's row: a second session that tries to lock it gives up at lock_timeout while the first
     * transaction is open, and another item of the same checklist stays free.
     */
    #[Test]
    public function aLockedItemHoldsItsRowAndOnlyItsRowUntilTheTransactionEnds(): void
    {
        $this->addCommitted(self::checklist(self::CHECKLIST, 1));
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $tenantContext);
        $this->otherSession = $secondSession->entityManager;
        $otherChecklists = new DbalChecklists($secondSession->entityManager->getConnection(), $tenantContext);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));

        $this->connection()->beginTransaction();
        try {
            $this->checklists()->lockedItem(new CheckItemId(self::FIRST_ITEM));
            $waitedAtTheLock = $secondSession->sqlStateOfFailedAttempt(static function () use ($otherChecklists): void {
                $otherChecklists->lockedItem(new CheckItemId(self::FIRST_ITEM));
            });
            $secondConnection = $secondSession->entityManager->getConnection();
            $secondConnection->beginTransaction();
            try {
                $otherItem = $otherChecklists->lockedItem(new CheckItemId(self::SECOND_ITEM));
            } finally {
                $secondConnection->rollBack();
            }
        } finally {
            $this->connection()->rollBack();
            $tenantContext->clear();
        }

        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $waitedAtTheLock, 'The second session did not wait for the locked item.');
        self::assertSame(self::SECOND_ITEM, $otherItem?->id()->toString());
    }

    /**
     * @param list<string> $itemIds one per pin of the report, in its order
     */
    private static function checklist(string $roundId, int $number, array $itemIds = [self::FIRST_ITEM, self::SECOND_ITEM, self::THIRD_ITEM]): Checklist
    {
        $pinIds = [new PinId(self::FIRST_PIN), new PinId(self::SECOND_PIN), new PinId(self::THIRD_PIN)];

        return Checklist::fromReport(
            new RoundId($roundId),
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            new ReportPins(new UserId(self::LENA), $pinIds),
            $number,
            static function () use (&$itemIds): CheckItemId {
                return new CheckItemId(array_shift($itemIds) ?? throw new \LogicException('More items than ids.'));
            },
            new \DateTimeImmutable(self::CREATED_AT),
        ) ?? throw new \LogicException('No pins.');
    }

    private static function pin(string $pinId, int $number): Pin
    {
        return Pin::placedBy(new PinId($pinId), $number, 40.0, 60.0, 'Looks off', new UserId(self::LENA));
    }

    private function addCommitted(Checklist $checklist): void
    {
        $this->transactions()->committedAs($checklist->organizationId()->toString(), function () use ($checklist): void {
            $this->checklists()->add($checklist);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsAs(string $organizationId, string $sql): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllAssociative($sql));
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<list<mixed>>
     */
    private function numericRowsAs(string $organizationId, string $sql, array $parameters = []): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllNumeric($sql, $parameters));
    }

    private function checklists(): DbalChecklists
    {
        return new DbalChecklists($this->connection(), self::getContainer()->get(TenantContext::class));
    }

    private function rounds(): DbalRounds
    {
        return new DbalRounds($this->connection(), self::getContainer()->get(TenantContext::class));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function roundRows(): RoundRows
    {
        return new RoundRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}

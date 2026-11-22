<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Checklist;

use App\Review\Api\CheckItemChange;
use App\Review\Api\ChecklistCommands;
use App\Review\Api\ChecklistRefused;
use App\Review\Api\Event\ChecklistItemChanged;
use App\Review\Domain\Round;
use App\Review\Domain\Rounds;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * ChecklistCommands as the container wires it, on the real tables, outbox, relay and ping clearing. Lena reported SHOP-1
 * of Acme Foods with three pins on two pictures; Omar marks them fixed and Mia checks them. Lena's two open pings to
 * Omar and one to Mia on SHOP-1 show whose pings a change clears. Beta Bakery has a report on a ticket with the same id.
 * A refusal is caught inside a transaction that then commits, so "unchanged" proves the check comes before the write.
 */
final class UseCaseChecklistCommandsTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f8c01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f8c02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000f8c03';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f8c04';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f8c05';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000f8c06';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f8c07';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f8c11';
    private const string BETA_REPORT = '0197a3c0-0000-7000-8000-0000000f8c12';
    private const string FIRST_PICTURE = '0197a3c0-0000-7000-8000-0000000f8c21';
    private const string SECOND_PICTURE = '0197a3c0-0000-7000-8000-0000000f8c22';
    private const string BETA_PICTURE = '0197a3c0-0000-7000-8000-0000000f8c23';
    /** Pin 2 of the first picture. */
    private const string WRONG_TOTAL_PIN = '0197a3c0-0000-7000-8000-0000000f8c31';
    private const string MISSING_PRICE_PIN = '0197a3c0-0000-7000-8000-0000000f8c32';
    private const string SMALL_LOGO_PIN = '0197a3c0-0000-7000-8000-0000000f8c33';
    private const string BETA_PIN = '0197a3c0-0000-7000-8000-0000000f8c34';
    private const string FIRST_PING_TO_OMAR = '0197a3c0-0000-7000-8000-0000000f8c41';
    private const string SECOND_PING_TO_OMAR = '0197a3c0-0000-7000-8000-0000000f8c42';
    private const string PING_TO_MIA = '0197a3c0-0000-7000-8000-0000000f8c43';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f8c51';
    private const string WRONG_TOTAL_NOTE = 'The total shows the old price.';
    private const string CREATED_AT = '2027-03-16 08:30:00+00:00';
    private const string NOW = '2027-03-16 09:30:00+00:00';

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA);
        $this->roundRows()->addAllOf(self::ACME_FOODS, [
            self::report(self::ACME_FOODS, self::REPORT),
            self::picture(self::ACME_FOODS, self::FIRST_PICTURE, self::REPORT, 0),
            self::picture(self::ACME_FOODS, self::SECOND_PICTURE, self::REPORT, 1),
            self::pin(self::ACME_FOODS, self::WRONG_TOTAL_PIN, self::FIRST_PICTURE, 2, self::WRONG_TOTAL_NOTE),
            self::pin(self::ACME_FOODS, self::MISSING_PRICE_PIN, self::FIRST_PICTURE, 1, 'No price on the card.'),
            self::pin(self::ACME_FOODS, self::SMALL_LOGO_PIN, self::SECOND_PICTURE, 1, 'Logo too small.'),
        ]);
        $this->roundRows()->addAllOf(self::BETA_BAKERY, [
            self::report(self::BETA_BAKERY, self::BETA_REPORT),
            self::picture(self::BETA_BAKERY, self::BETA_PICTURE, self::BETA_REPORT, 0),
            self::pin(self::BETA_BAKERY, self::BETA_PIN, self::BETA_PICTURE, 2, 'Wrong colour.'),
        ]);
        $this->addOpenPings();
        $this->deleteOutboxRowsNaming(self::TICKET);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $this->deleteOutboxRowsNaming(self::TICKET);
        parent::tearDown();
    }

    #[Test]
    public function theChecklistIsSharedWithNoNumberFromRoundZerosPinsAndIsMadeOnce(): void
    {
        $roundId = $this->createChecklist();
        $secondRoundId = $this->createChecklist();

        self::assertNotNull($roundId);
        self::assertNull($secondRoundId);
        self::assertSame(
            [[$roundId, null, self::LENA, true, 'open']],
            $this->rowsAs("SELECT id, number, owner_id, shared_at IS NOT NULL, state FROM rvw_round WHERE kind = 'checklist'", [], all: true),
        );
        self::assertSame(
            [[self::WRONG_TOTAL_PIN, 'open', null], [self::MISSING_PRICE_PIN, 'open', null], [self::SMALL_LOGO_PIN, 'open', null]],
            $this->rowsAs('SELECT pin_id, state, changed_by FROM rvw_check_item WHERE round_id = ? ORDER BY pin_id', [$roundId], all: true),
        );
        self::assertSame([], $this->outboxBodiesNaming(self::TICKET));
    }

    #[Test]
    public function omarMarksAPinFixedWithOneEventWithoutItsNoteAndOnlyHisPingsAreCleared(): void
    {
        $this->createChecklist();
        $itemId = $this->itemIdOf(self::WRONG_TOTAL_PIN);

        $change = $this->transactions()->committedAs(self::ACME_FOODS, fn (): CheckItemChange => $this->commands()->markFixed($itemId, new UserId(self::OMAR), new \DateTimeImmutable(self::NOW)));

        self::assertEquals(new CheckItemChange(2, ChecklistCommands::STATE_FIXED, 2), $change);
        self::assertSame([['fixed', self::OMAR, '2027-03-16 09:30:00']], $this->rowsAs(
            "SELECT state, changed_by, to_char(changed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM rvw_check_item WHERE id = ?",
            [$itemId],
            all: true,
        ));
        $outboxBodies = $this->outboxBodiesNaming($itemId);
        self::assertCount(1, $outboxBodies);
        self::assertStringContainsString(str_replace('\\', '', ChecklistItemChanged::class), str_replace('\\', '', $outboxBodies[0]));
        self::assertStringNotContainsString('old price', $outboxBodies[0]);
        self::assertSame(
            [[self::FIRST_PING_TO_OMAR, 'acted'], [self::SECOND_PING_TO_OMAR, 'acted'], [self::PING_TO_MIA, null]],
            $this->rowsAs('SELECT id, cleared_reason FROM ntf_ping ORDER BY id', [], all: true),
        );
    }

    #[Test]
    public function theRelayHandsTheChecklistEventToAsyncNotify(): void
    {
        $roundId = $this->createChecklist();
        $itemId = $this->itemIdOf(self::WRONG_TOTAL_PIN);
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): CheckItemChange => $this->commands()->markFixed($itemId, new UserId(self::OMAR), new \DateTimeImmutable(self::NOW)));

        self::runTheWorkerUntilIdle('outbox');

        $relayed = array_values(array_filter(
            array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->asyncNotify()->getSent()),
            static fn (object $message): bool => $message instanceof ChecklistItemChanged && $itemId === $message->itemId,
        ));
        self::assertEquals(
            [new ChecklistItemChanged(self::ACME_FOODS, self::TICKET, (string) $roundId, $itemId, 'fixed', self::OMAR, new \DateTimeImmutable(self::NOW))],
            $relayed,
        );
    }

    #[Test]
    public function confirmingAnOpenItemOkIsRefusedAndWritesAndClearsNothing(): void
    {
        $this->createChecklist();
        $itemId = $this->itemIdOf(self::WRONG_TOTAL_PIN);

        $refusal = $this->refusalAs(self::ACME_FOODS, fn () => $this->commands()->markOk($itemId, new UserId(self::MIA), new \DateTimeImmutable(self::NOW)));

        self::assertSame('Only a check marked fixed can be confirmed OK.', $refusal?->getMessage());
        self::assertSame([['open', null]], $this->rowsAs('SELECT state, changed_by FROM rvw_check_item WHERE id = ?', [$itemId], all: true));
        self::assertSame([], $this->outboxBodiesNaming(self::TICKET));
        self::assertSame([null], $this->rowsAs('SELECT cleared_reason FROM ntf_ping WHERE id = ?', [self::PING_TO_MIA]));
    }

    #[Test]
    public function betaBakerysItemIsNotFoundUnderAcmeFoodsAndStaysOpen(): void
    {
        $betaRoundId = $this->transactions()->committedAs(self::BETA_BAKERY, fn (): ?string => $this->commands()->createFromReport(new TicketId(self::TICKET), new \DateTimeImmutable(self::CREATED_AT)));
        self::assertNotNull($betaRoundId);
        $betaItemId = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): mixed => $this->connection()->fetchOne('SELECT id FROM rvw_check_item WHERE pin_id = ?', [self::BETA_PIN]));
        self::assertIsString($betaItemId);

        $refusal = $this->refusalAs(self::ACME_FOODS, fn () => $this->commands()->markFixed($betaItemId, new UserId(self::OMAR), new \DateTimeImmutable(self::NOW)));

        self::assertSame(ChecklistRefused::NOT_FOUND, $refusal?->getMessage());
        self::assertTrue($refusal->isNotFound());
        self::assertSame(['open'], $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->connection()->fetchFirstColumn('SELECT state FROM rvw_check_item WHERE id = ?', [$betaItemId])));
        self::assertSame([], $this->outboxBodiesNaming(self::TICKET));
        self::assertSame([null, null, null], $this->rowsAs('SELECT cleared_reason FROM ntf_ping ORDER BY id', []));
    }

    #[Test]
    public function aRoundSharedWhenOnlyRoundZeroAndTheChecklistExistIsRoundOneAndTheNextIsRoundTwo(): void
    {
        self::assertNotNull($this->createChecklist());

        $numbers = $this->transactions()->committedAs(self::ACME_FOODS, static function (): array {
            $rounds = self::getContainer()->get(Rounds::class);
            $numbers = [];
            foreach ([self::OMAR, self::MIA] as $ownerId) {
                $owner = new UserId($ownerId);
                $draft = Round::draft($rounds->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), $owner, new \DateTimeImmutable(self::NOW));
                $draft->addScreenshot($owner, $rounds->nextScreenshotIdentity(), self::MEDIA);
                $number = $rounds->nextFreeNumberOf(new TicketId(self::TICKET));
                $draft->share($owner, $number, new \DateTimeImmutable(self::NOW));
                $rounds->add($draft);
                $numbers[] = $number;
            }

            return $numbers;
        });

        self::assertSame([1, 2], $numbers);
        self::assertSame(
            [['report', 0], ['checklist', null], ['draft', 1], ['draft', 2]],
            $this->rowsAs('SELECT kind, number FROM rvw_round WHERE ticket_id = ? ORDER BY COALESCE(number, 0), kind DESC', [self::TICKET], all: true),
        );
    }

    private function createChecklist(): ?string
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->commands()->createFromReport(new TicketId(self::TICKET), new \DateTimeImmutable(self::CREATED_AT)));
    }

    private function itemIdOf(string $pinId): string
    {
        $itemId = $this->rowsAs('SELECT id FROM rvw_check_item WHERE pin_id = ?', [$pinId])[0] ?? null;
        self::assertIsString($itemId);

        return $itemId;
    }

    /**
     * @param \Closure(): mixed $change
     */
    private function refusalAs(string $organizationId, \Closure $change): ?ChecklistRefused
    {
        return $this->transactions()->committedAs($organizationId, static function () use ($change): ?ChecklistRefused {
            try {
                $change();
            } catch (ChecklistRefused $refused) {
                return $refused;
            }

            return null;
        });
    }

    /**
     * Lena's two open pings to Omar and one to Mia on SHOP-1; removing the ticket removes them.
     */
    private function addOpenPings(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->migrator()->transactional(static function (Connection $connection): void {
                foreach ([[self::FIRST_PING_TO_OMAR, self::OMAR], [self::SECOND_PING_TO_OMAR, self::OMAR], [self::PING_TO_MIA, self::MIA]] as [$pingId, $toId]) {
                    $connection->executeStatement(
                        "INSERT INTO ntf_ping (organization_id, id, ticket_id, from_id, to_id, body, created_at) VALUES (?, ?, ?, ?, ?, 'Can you look at this today?', '2027-03-16 08:00:00+00')",
                        [self::ACME_FOODS, $pingId, self::TICKET, self::LENA, $toId],
                    );
                }
            });
        } finally {
            $this->tenantContext()->clear();
        }
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed> the first column, or each row's values with $all
     */
    private function rowsAs(string $sql, array $parameters, bool $all = false): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $all
            ? $this->connection()->fetchAllNumeric($sql, $parameters)
            : $this->connection()->fetchFirstColumn($sql, $parameters));
    }

    /**
     * Only the outbox rows naming this test's ids: other tests delete outbox rows at the same time.
     *
     * @return list<string>
     */
    private function outboxBodiesNaming(string $id): array
    {
        $bodies = [];
        foreach ($this->connection()->fetchFirstColumn("SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ? ORDER BY id", ['%'.$id.'%']) as $body) {
            self::assertIsString($body);
            $bodies[] = $body;
        }

        return $bodies;
    }

    private function deleteOutboxRowsNaming(string $id): void
    {
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ?", ['%'.$id.'%']);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function report(string $organizationId, string $roundId): array
    {
        return ['rvw_round', [
            'organization_id' => $organizationId,
            'id' => $roundId,
            'ticket_id' => self::TICKET,
            'number' => 0,
            'kind' => 'report',
            'owner_id' => self::LENA,
            'shared_at' => '2027-03-15 10:00:00+00',
            'state' => 'closed',
            'version' => 1,
            'created_at' => '2027-03-15 10:00:00+00',
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function picture(string $organizationId, string $screenshotId, string $roundId, int $position): array
    {
        return ['rvw_screenshot', [
            'organization_id' => $organizationId,
            'id' => $screenshotId,
            'round_id' => $roundId,
            'media_id' => self::MEDIA,
            'position' => $position,
            'created_at' => '2027-03-15 10:00:00+00',
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function pin(string $organizationId, string $pinId, string $screenshotId, int $number, string $note): array
    {
        return ['rvw_pin', [
            'organization_id' => $organizationId, 'id' => $pinId, 'screenshot_id' => $screenshotId, 'number' => $number, 'x_pct' => '40.00',
            'y_pct' => '60.00', 'note' => $note, 'source' => 'human', 'author_id' => self::LENA, 'created_at' => '2027-03-15 10:00:00+00',
        ]];
    }

    private function commands(): ChecklistCommands
    {
        $commands = self::getContainer()->get('test.review_checklist_commands');
        self::assertInstanceOf(ChecklistCommands::class, $commands);

        return $commands;
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows($this->migrator(), $this->tenantContext());
    }

    private function roundRows(): RoundRows
    {
        return new RoundRows($this->migrator(), $this->tenantContext());
    }
}

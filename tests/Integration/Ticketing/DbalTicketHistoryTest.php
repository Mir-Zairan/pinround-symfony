<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\Insight\Api\TurnWait;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Application\LastTicketChange;
use App\Ticketing\Application\TicketHistory;
use App\Ticketing\Domain\MovedFrom;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketHistory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalTicketHistory::recordMoved as pinround_app under row-level security, with Acme Foods and Beta Bakery each having
 * a ticket in a project with the same id. The rows are dated 2027-03-16, inside the fixed monthly partitions.
 */
final class DbalTicketHistoryTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e801';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000e802';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000e811';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000e821';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e851';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e852';
    private const string MOVED_AT = '2027-03-16 09:30:00 UTC';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
        ]);
        $this->shopTeamRows()->add(self::BETA_BAKERY, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, []);
        $this->shopTeamRows()->addMember(self::BETA_BAKERY, self::SHOP_TEAM, self::LENA, 'lead', 'developer');
        $this->shopTeamRows()->addMember(self::BETA_BAKERY, self::SHOP_TEAM, self::OMAR, 'member', 'developer');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    /**
     * Four moves of one ticket, so each row's payload holds exactly the parts its move had, and the created row of the
     * ticket is not among them.
     */
    #[Test]
    public function itRecordsOneMovedRowPerMoveWithItsStepsActorAndPayload(): void
    {
        $ticketId = $this->createdTicketOf(self::ACME_FOODS);

        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($ticketId): void {
            $this->recordMoveOf(self::ACME_FOODS, $ticketId, ShopTeamRows::TO_DO_STEP, ShopTeamRows::BACKLOG_STEP, 'put_on_hold', null, '2027-03-16 09:30:00 UTC');
            $this->recordMoveOf(self::ACME_FOODS, $ticketId, ShopTeamRows::BACKLOG_STEP, ShopTeamRows::TO_DO_STEP, null, null, '2027-03-16 09:31:00 UTC');
            $this->recordMoveOf(self::ACME_FOODS, $ticketId, ShopTeamRows::TO_DO_STEP, ShopTeamRows::BACKLOG_STEP, 'put_on_hold', 'Customer asked to wait', '2027-03-16 09:32:00 UTC');
            $this->recordMoveOf(self::ACME_FOODS, $ticketId, ShopTeamRows::BACKLOG_STEP, ShopTeamRows::TO_DO_STEP, null, 'Release is frozen', '2027-03-16 09:33:00 UTC');
        });

        self::assertSame([
            [ShopTeamRows::TO_DO_STEP, ShopTeamRows::BACKLOG_STEP, self::LENA, ['verb' => 'put_on_hold'], '2027-03-16 09:30:00+00'],
            [ShopTeamRows::BACKLOG_STEP, ShopTeamRows::TO_DO_STEP, self::LENA, [], '2027-03-16 09:31:00+00'],
            [ShopTeamRows::TO_DO_STEP, ShopTeamRows::BACKLOG_STEP, self::LENA, ['verb' => 'put_on_hold', 'override_note' => 'Customer asked to wait'], '2027-03-16 09:32:00+00'],
            [ShopTeamRows::BACKLOG_STEP, ShopTeamRows::TO_DO_STEP, self::LENA, ['override_note' => 'Release is frozen'], '2027-03-16 09:33:00+00'],
        ], $this->movedRowsOf(self::ACME_FOODS, $ticketId));
    }

    /**
     * Under Beta Bakery's tenant, a row naming Acme Foods breaks row-level security (42501), and a row naming Beta
     * Bakery with Acme Foods' ticket has no ticket to point at (23503); neither is written.
     */
    #[Test]
    public function anotherOrganizationsTicketGetsNoMovedRow(): void
    {
        $acmeTicketId = $this->createdTicketOf(self::ACME_FOODS);

        $sqlStates = [];
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $namedOrganizationId) {
            $sqlStates[] = $this->transactions()->rolledBackAs(self::BETA_BAKERY, function () use ($namedOrganizationId, $acmeTicketId): ?string {
                try {
                    $this->recordMoveOf($namedOrganizationId, $acmeTicketId, ShopTeamRows::TO_DO_STEP, ShopTeamRows::BACKLOG_STEP, 'put_on_hold', null, self::MOVED_AT);
                } catch (DriverException $refusal) {
                    return $refusal->getSQLState();
                }

                return null;
            });
        }

        self::assertSame(['42501', '23503'], $sqlStates);
        self::assertSame([], $this->movedRowsOf(self::ACME_FOODS, $acmeTicketId));
        self::assertSame([], $this->movedRowsOf(self::BETA_BAKERY, $acmeTicketId));
    }

    #[Test]
    public function recordingAMoveOutsideATransactionIsRefusedAndWritesNothing(): void
    {
        $ticketId = $this->createdTicketOf(self::ACME_FOODS);

        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->recordMoveOf(self::ACME_FOODS, $ticketId, ShopTeamRows::TO_DO_STEP, ShopTeamRows::BACKLOG_STEP, 'put_on_hold', null, self::MOVED_AT);
            self::fail('A move was recorded with no transaction open.');
        } catch (\LogicException $refusal) {
            self::assertSame('A ticket event is recorded in the transaction of its change; none is open.', $refusal->getMessage());
        } finally {
            self::getContainer()->get(TenantContext::class)->clear();
        }

        self::assertSame([], $this->movedRowsOf(self::ACME_FOODS, $ticketId));
    }

    /**
     * The turn a move took from a person and the one it took from a label both come back to Undo as they were kept.
     */
    #[Test]
    public function theLastMoveGivesBackTheTurnWaitItKept(): void
    {
        $ticketId = $this->createdTicketOf(self::ACME_FOODS);
        $movedFrom = new MovedFrom(ShopTeamRows::TO_DO_STEP, null, null, null, null, null, null);
        $waits = [
            new TurnWait(new UserId(self::OMAR), null, new \DateTimeImmutable('2027-03-07 08:15:00 UTC')),
            new TurnWait(null, 'Customer', new \DateTimeImmutable('2027-03-08 10:00:00 UTC')),
        ];

        $keptWaits = [];
        foreach ($waits as $minute => $wait) {
            $keptWaits[] = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($ticketId, $movedFrom, $wait, $minute): ?TurnWait {
                $this->recordMoveOf(self::ACME_FOODS, $ticketId, ShopTeamRows::TO_DO_STEP, ShopTeamRows::BACKLOG_STEP, 'put_on_hold', null, \sprintf('2027-03-16 09:3%d:00 UTC', $minute), $movedFrom, $wait);

                return $this->lastChangeOf($ticketId)?->turnWaitBefore;
            });
        }

        self::assertEquals($waits, $keptWaits);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function turnWaitsOfAnotherShape(): iterable
    {
        yield 'neither a person nor a label' => ['{"user_id": null, "label": null, "since": "2027-03-07T08:15:00.000000+00:00"}'];
        yield 'both a person and a label' => ['{"user_id": "'.self::OMAR.'", "label": "Customer", "since": "2027-03-07T08:15:00.000000+00:00"}'];
        yield 'no since' => ['{"user_id": "'.self::OMAR.'", "label": null}'];
        yield 'a since that is no time' => ['{"user_id": "'.self::OMAR.'", "label": null, "since": "last week"}'];
        yield 'a person id that is no user id' => ['{"user_id": "omar", "label": null, "since": "2027-03-07T08:15:00.000000+00:00"}'];
        yield 'no object' => ['"2027-03-07T08:15:00.000000+00:00"'];
    }

    /**
     * A payload another writer may have left reads as no kept wait, so the Undo starts a new one, and its moved-from
     * fields are still read.
     */
    #[Test]
    #[DataProvider('turnWaitsOfAnotherShape')]
    public function aTurnWaitOfAnotherShapeReadsAsNone(string $turnWaitJson): void
    {
        $ticketId = $this->createdTicketOf(self::ACME_FOODS);
        $movedFrom = new MovedFrom(ShopTeamRows::TO_DO_STEP, null, null, null, null, null, null);
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->recordMoveOf(self::ACME_FOODS, $ticketId, ShopTeamRows::TO_DO_STEP, ShopTeamRows::BACKLOG_STEP, 'put_on_hold', null, self::MOVED_AT, $movedFrom, new TurnWait(new UserId(self::OMAR), null, new \DateTimeImmutable(self::MOVED_AT))));
        $migrator = self::getContainer()->get('doctrine.dbal.migrator_connection');
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));
        try {
            $changedRows = $migrator->transactional(static fn (Connection $connection): int|string => $connection->executeStatement(
                "UPDATE tkt_ticket_event SET payload = jsonb_set(payload, '{".TicketHistory::TURN_WAIT_BEFORE_PAYLOAD_KEY."}', CAST(? AS jsonb)) WHERE ticket_id = ? AND type = 'moved'",
                [$turnWaitJson, $ticketId],
            ));
        } finally {
            self::getContainer()->get(TenantContext::class)->clear();
        }
        self::assertSame(1, $changedRows);

        $lastChange = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?LastTicketChange => $this->lastChangeOf($ticketId));

        self::assertNotNull($lastChange);
        self::assertEquals($movedFrom, $lastChange->movedFrom);
        self::assertNull($lastChange->turnWaitBefore);
    }

    private function lastChangeOf(string $ticketId): ?LastTicketChange
    {
        return new DbalTicketHistory($this->entityManager()->getConnection(), self::getContainer()->get(TenantContext::class))
            ->lastChangeOf(new TicketId($ticketId), new \DateTimeImmutable('2026-01-01 00:00:00 UTC'));
    }

    private function recordMoveOf(string $organizationId, string $ticketId, string $fromStepId, string $toStepId, ?string $verb, ?string $overrideNote, string $movedAt, ?MovedFrom $movedFrom = null, ?TurnWait $turnWaitBefore = null): void
    {
        new DbalTicketHistory($this->entityManager()->getConnection(), self::getContainer()->get(TenantContext::class))->recordMoved(
            new OrganizationId($organizationId),
            new TicketId($ticketId),
            new UserId(self::LENA),
            $fromStepId,
            $toStepId,
            $verb,
            $overrideNote,
            new \DateTimeImmutable($movedAt),
            $movedFrom,
            $turnWaitBefore,
        );
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed, mixed}> from step, to step, actor, decoded payload and time, oldest first
     */
    private function movedRowsOf(string $organizationId, string $ticketId): array
    {
        $rows = $this->transactions()->rolledBackAs($organizationId, function () use ($ticketId): array {
            $connection = $this->entityManager()->getConnection();
            $connection->executeStatement("SET LOCAL TIME ZONE 'UTC'");

            return $connection->fetchAllNumeric(
                "SELECT from_step, to_step, actor_id, payload, at FROM tkt_ticket_event WHERE ticket_id = ? AND type = 'moved' ORDER BY at",
                [$ticketId],
            );
        });

        return array_map(static function (array $row): array {
            self::assertIsString($row[3]);

            return [$row[0], $row[1], $row[2], json_decode($row[3], true, flags: \JSON_THROW_ON_ERROR), $row[4]];
        }, $rows);
    }

    private function createdTicketOf(string $organizationId): string
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);

        return new ShopTickets($ticketCommands, $this->transactions(), $this->entityManager()->getConnection())
            ->createdFor($organizationId, self::SHOP_PROJECT, self::LENA, self::OMAR)
            ->toString();
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }
}

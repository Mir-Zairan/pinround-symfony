<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketHistory;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
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

    private function recordMoveOf(string $organizationId, string $ticketId, string $fromStepId, string $toStepId, ?string $verb, ?string $overrideNote, string $movedAt): void
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

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Application\TicketHistoryEntry;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketHistoryEntries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalTicketHistoryEntries::latestOf as pinround_app under row-level security. ShopTickets creates each ticket at
 * 2027-03-15 10:00 UTC; the rows are written straight by the migrator, so their times and ids are the test's.
 */
final class DbalTicketHistoryEntriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e901';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000e902';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000e911';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000e921';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e951';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e952';
    private const string TICKET_CREATED_AT = '2027-03-15 10:00:00 UTC';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
        ]);
        $this->shopTeamRows()->add(self::BETA_BAKERY, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, []);
        $this->shopTeamRows()->addMember(self::BETA_BAKERY, self::SHOP_TEAM, self::LENA, 'lead', 'developer');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    /**
     * Eight moves written out of order, two of them at the same time: the six newest come, newest first, and of the
     * two at one time the higher id first, though it was written second.
     */
    #[Test]
    public function itReadsTheSixNewestMovesNewestFirstWithTiesByIdNewestFirst(): void
    {
        $ticketId = $this->createdTicketOf(self::ACME_FOODS);
        $this->addEvents(self::ACME_FOODS, $ticketId->toString(), [
            ['0197a3c0-0000-7000-8000-0000000ee003', 'moved', '2027-03-16 09:03:00+00', '{}'],
            ['0197a3c0-0000-7000-8000-0000000ee007', 'moved', '2027-03-16 09:07:00+00', '{}'],
            ['0197a3c0-0000-7000-8000-0000000ee001', 'moved', '2027-03-16 09:01:00+00', '{}'],
            ['0197a3c0-0000-7000-8000-0000000ee008', 'moved', '2027-03-16 09:07:00+00', '{}'],
            ['0197a3c0-0000-7000-8000-0000000ee005', 'moved', '2027-03-16 09:05:00+00', '{}'],
            ['0197a3c0-0000-7000-8000-0000000ee002', 'moved', '2027-03-16 09:02:00+00', '{}'],
            ['0197a3c0-0000-7000-8000-0000000ee006', 'moved', '2027-04-02 08:00:00+00', '{}'],
            ['0197a3c0-0000-7000-8000-0000000ee004', 'moved', '2027-03-16 09:04:00+00', '{}'],
        ]);

        $entries = $this->latestOf(self::ACME_FOODS, $ticketId);

        self::assertSame(
            ['2027-04-02 08:00', '2027-03-16 09:07', '2027-03-16 09:07', '2027-03-16 09:05', '2027-03-16 09:04', '2027-03-16 09:03'],
            array_map(static fn (TicketHistoryEntry $entry): string => $entry->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i'), $entries),
        );
        self::assertSame(
            ['0197a3c0-0000-7000-8000-0000000ee006', '0197a3c0-0000-7000-8000-0000000ee008', '0197a3c0-0000-7000-8000-0000000ee007'],
            \array_slice($this->idsInReadOrder(self::ACME_FOODS, $ticketId), 0, 3),
        );
    }

    /**
     * The ticket's own two moves come, with their parts: not its created row, not a move dated before the ticket (one
     * in its month, one in the month before), not another ticket's move, and nothing under another organization.
     */
    #[Test]
    public function itReadsOnlyThisTicketsMovesSinceItWasCreated(): void
    {
        $ticketId = $this->createdTicketOf(self::ACME_FOODS);
        $otherTicketId = $this->createdTicketOf(self::ACME_FOODS);
        $this->addEvents(self::ACME_FOODS, $ticketId->toString(), [
            ['0197a3c0-0000-7000-8000-0000000ef001', 'moved', '2027-03-15 09:59:59+00', '{}'],
            ['0197a3c0-0000-7000-8000-0000000ef002', 'moved', '2027-02-20 10:00:00+00', '{}'],
            ['0197a3c0-0000-7000-8000-0000000ef003', 'moved', '2027-03-16 09:00:00+00', '{"verb": "start_work"}'],
            ['0197a3c0-0000-7000-8000-0000000ef004', 'moved', '2027-03-16 09:05:00+00', '{"verb": "give_it_to", "assignee_id": "'.self::OMAR.'"}'],
        ]);
        $this->addEvents(self::ACME_FOODS, $otherTicketId->toString(), [
            ['0197a3c0-0000-7000-8000-0000000ef005', 'moved', '2027-03-16 09:10:00+00', '{}'],
        ]);

        $entries = $this->latestOf(self::ACME_FOODS, $ticketId);

        self::assertCount(2, $entries);
        self::assertSame(
            [
                [self::LENA, ShopTeamRows::BACKLOG_STEP, ShopTeamRows::TO_DO_STEP, 'give_it_to', self::OMAR, true],
                [self::LENA, ShopTeamRows::BACKLOG_STEP, ShopTeamRows::TO_DO_STEP, 'start_work', null, false],
            ],
            array_map(static fn (TicketHistoryEntry $entry): array => [
                $entry->actorId->toString(), $entry->fromStepId, $entry->toStepId, $entry->verb, $entry->givenToId?->toString(), $entry->isGive(),
            ], $entries),
        );
        self::assertSame([], $this->latestOf(self::BETA_BAKERY, $ticketId));
    }

    #[Test]
    public function aGiveWhosePayloadNamesNoUserIdGivesToNobodyKnown(): void
    {
        $ticketId = $this->createdTicketOf(self::ACME_FOODS);
        $this->addEvents(self::ACME_FOODS, $ticketId->toString(), [
            ['0197a3c0-0000-7000-8000-0000000ef101', 'moved', '2027-03-16 09:00:00+00', '{"verb": "give_it_to", "assignee_id": "not-a-user"}'],
        ]);

        $entries = $this->latestOf(self::ACME_FOODS, $ticketId);

        self::assertCount(1, $entries);
        self::assertTrue($entries[0]->isGive());
        self::assertNull($entries[0]->givenToId);
    }

    #[Test]
    public function readingOutsideATransactionIsRefused(): void
    {
        $ticketId = $this->createdTicketOf(self::ACME_FOODS);
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));

        try {
            $this->historyEntries()->latestOf($ticketId, new \DateTimeImmutable(self::TICKET_CREATED_AT));
            self::fail('The history was read with no transaction open.');
        } catch (\LogicException $refusal) {
            self::assertSame('A ticket\'s history is read in a transaction under the organization as tenant; none is open.', $refusal->getMessage());
        } finally {
            self::getContainer()->get(TenantContext::class)->clear();
        }
    }

    /**
     * The table holds monthly partitions from 2026-11 on; the bound on the ticket's creation (March 2027) leaves the
     * months before it out of the plan.
     */
    #[Test]
    public function thePlanReadsNoPartitionBeforeTheTicketsMonth(): void
    {
        $ticketId = $this->createdTicketOf(self::ACME_FOODS);

        $planLines = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($ticketId): array {
            $connection = $this->connection();
            self::assertSame(['pinround_app', 'on'], [$connection->fetchOne('SELECT current_user'), $connection->fetchOne('SHOW row_security')]);

            return $connection->fetchFirstColumn(
                'EXPLAIN '.DbalTicketHistoryEntries::LATEST_SQL,
                DbalTicketHistoryEntries::parametersOf(self::ACME_FOODS, $ticketId, new \DateTimeImmutable(self::TICKET_CREATED_AT)),
            );
        });
        $plan = implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $planLines));
        preg_match_all('/tkt_ticket_event_(\d{6})/', $plan, $months);

        self::assertContains('202703', $months[1], $plan);
        self::assertSame([], array_values(array_filter($months[1], static fn (string $month): bool => $month < '202703')), $plan);
    }

    /**
     * @param list<array{string, string, string, string}> $events id, type, time and payload of each row, written in
     *                                                            this order by Lena, from Backlog to To do
     */
    private function addEvents(string $organizationId, string $ticketId, array $events): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional(static function (Connection $migrator) use ($organizationId, $ticketId, $events): void {
                foreach ($events as [$id, $type, $at, $payload]) {
                    $migrator->executeStatement(
                        'INSERT INTO tkt_ticket_event (id, organization_id, ticket_id, actor_id, type, from_step, to_step, payload, at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS timestamptz))',
                        [$id, $organizationId, $ticketId, self::LENA, $type, ShopTeamRows::BACKLOG_STEP, ShopTeamRows::TO_DO_STEP, $payload, $at],
                    );
                }
            });
        } finally {
            $tenantContext->clear();
        }
    }

    /**
     * @return list<TicketHistoryEntry>
     */
    private function latestOf(string $organizationId, TicketId $ticketId): array
    {
        return $this->transactions()->rolledBackAs(
            $organizationId,
            fn (): array => $this->historyEntries()->latestOf($ticketId, new \DateTimeImmutable(self::TICKET_CREATED_AT)),
        );
    }

    /**
     * The entries carry no row id, so the order of ties is read from the same query's ids.
     *
     * @return list<mixed>
     */
    private function idsInReadOrder(string $organizationId, TicketId $ticketId): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchFirstColumn(
            DbalTicketHistoryEntries::LATEST_SQL,
            DbalTicketHistoryEntries::parametersOf($organizationId, $ticketId, new \DateTimeImmutable(self::TICKET_CREATED_AT)),
        ));
    }

    private function historyEntries(): DbalTicketHistoryEntries
    {
        return new DbalTicketHistoryEntries($this->connection(), self::getContainer()->get(TenantContext::class));
    }

    private function createdTicketOf(string $organizationId): TicketId
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);

        return new ShopTickets($ticketCommands, $this->transactions(), $this->connection())
            ->createdFor($organizationId, self::SHOP_PROJECT, self::LENA, self::OMAR);
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
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

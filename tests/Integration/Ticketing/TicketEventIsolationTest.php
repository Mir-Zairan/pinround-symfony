<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Infrastructure\Doctrine\TicketEventPartitions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * tkt_ticket_event as pinround_app (Version20261111140000): another organization neither sees nor adds history of the
 * organization, a partition keeps the rows to its own tenant when queried directly, the app only reads and adds, and
 * the foreign keys of tkt_ticket_event and tkt_ticket refuse a ticket or project that is not the organization's. The
 * table also refuses a payload that is not an object and an event in a month with no partition. The rows sit in a far month whose partition the test adds and drops, so they never depend on the wall clock.
 */
final class TicketEventIsolationTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000034a1';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000034a2';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000034d1';
    private const string OTHER_PROJECT = '0197a3c0-0000-7000-8000-0000000034d2';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000034e1';
    private const string UNKNOWN_TICKET = '0197a3c0-0000-7000-8000-0000000034e2';
    private const string EVENT = '0197a3c0-0000-7000-8000-0000000034f1';
    private const string SMUGGLED_EVENT = '0197a3c0-0000-7000-8000-0000000034f2';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000034b1';
    private const string AT = '2030-01-15 10:00:00+00';
    private const string INSERT = "INSERT INTO tkt_ticket_event (id, organization_id, ticket_id, actor_id, type, payload, at) VALUES (?, ?, ?, ?, 'reported', '{}', ?)";

    private bool $hasAddedThePartition = false;

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        $this->addThePartitionOfTheMonth();
        $this->rows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA);
        $this->rows()->addProjectOf(self::OTHER_ORGANIZATION, self::OTHER_PROJECT);
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            self::INSERT,
            [self::EVENT, self::ACME_FOODS, self::TICKET, self::LENA, self::AT],
        ));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        if ($this->hasAddedThePartition) {
            $this->migrator()->executeStatement('DROP TABLE '.TicketEventPartitions::partitionNameFor(new \DateTimeImmutable(self::AT)));
        }
        parent::tearDown();
    }

    #[Test]
    public function anotherOrganizationFindsNoEventOfTheOrganization(): void
    {
        $seenByOther = $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): array => $this->connection()->fetchFirstColumn('SELECT id FROM tkt_ticket_event'));
        $seenByOwn = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn('SELECT id FROM tkt_ticket_event'));

        self::assertSame([], $seenByOther);
        self::assertSame([self::EVENT], $seenByOwn, 'The event is not found under its own organization either, so the check above proves nothing.');
    }

    #[Test]
    public function aPartitionQueriedDirectlyKeepsItsRowsToTheirOwnOrganization(): void
    {
        // The migrator owns the partition, so only the partition's own row-level security stands between it and the rows.
        $partition = TicketEventPartitions::partitionNameFor(new \DateTimeImmutable(self::AT));

        $seenByOther = $this->asMigratorUnder(self::OTHER_ORGANIZATION, static fn (Connection $migrator): array => $migrator->fetchFirstColumn('SELECT id FROM '.$partition));
        $seenByOwn = $this->asMigratorUnder(self::ACME_FOODS, static fn (Connection $migrator): array => $migrator->fetchFirstColumn('SELECT id FROM '.$partition));

        self::assertSame([], $seenByOther);
        self::assertSame([self::EVENT], $seenByOwn, 'The event is not in the partition under its own organization either, so the check above proves nothing.');
    }

    #[Test]
    public function theAppCannotReadAPartitionDirectly(): void
    {
        $partition = TicketEventPartitions::partitionNameFor(new \DateTimeImmutable(self::AT));

        $this->assertRefused(
            '42501',
            'permission denied for table '.$partition,
            fn (): array => $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn('SELECT id FROM '.$partition)),
        );
    }

    #[Test]
    public function anotherOrganizationCannotAddAnEventInTheNameOfTheOrganization(): void
    {
        $this->assertRefused(
            '42501',
            'new row violates row-level security policy for table "tkt_ticket_event"',
            fn (): int|string => $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): int|string => $this->connection()->executeStatement(
                self::INSERT,
                [self::SMUGGLED_EVENT, self::ACME_FOODS, self::TICKET, self::LENA, self::AT],
            )),
        );
    }

    #[Test]
    public function theAppCanNeitherChangeNorRemoveAnEvent(): void
    {
        foreach (["UPDATE tkt_ticket_event SET type = 'changed' WHERE id = ?", 'DELETE FROM tkt_ticket_event WHERE id = ?'] as $sql) {
            $this->assertRefused(
                '42501',
                'permission denied for table tkt_ticket_event',
                fn (): int|string => $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement($sql, [self::EVENT])),
            );
        }

        self::assertSame(
            ['reported'],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn('SELECT type FROM tkt_ticket_event')),
        );
    }

    #[Test]
    public function anEventOfATicketTheOrganizationDoesNotHaveIsRefused(): void
    {
        $this->assertRefused(
            '23503',
            'tkt_ticket_event_ticket_fkey',
            fn (): int|string => $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
                self::INSERT,
                [self::SMUGGLED_EVENT, self::ACME_FOODS, self::UNKNOWN_TICKET, self::LENA, self::AT],
            )),
        );
    }

    #[Test]
    public function aTicketInAProjectOfAnotherOrganizationIsRefused(): void
    {
        $this->assertRefused(
            '23503',
            'tkt_ticket_project_fkey',
            fn (): int|string => $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, number, key, title, description, type, priority, priority_rank, step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, 2, 'SHOP-2', 'Checkout button is hidden', '', 'bug', 'major', 2, ?, ?, 'all', '{}', '{}', now(), now())",
                [self::ACME_FOODS, self::UNKNOWN_TICKET, self::OTHER_PROJECT, self::SHOP_PROJECT, self::LENA],
            )),
        );
    }

    #[Test]
    public function aPayloadThatIsNotAnObjectIsRefused(): void
    {
        $this->assertRefused(
            '23514',
            'tkt_ticket_event_payload_check',
            fn (): int|string => $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
                "INSERT INTO tkt_ticket_event (id, organization_id, ticket_id, actor_id, type, payload, at) VALUES (?, ?, ?, ?, 'reported', '[]', ?)",
                [self::SMUGGLED_EVENT, self::ACME_FOODS, self::TICKET, self::LENA, self::AT],
            )),
        );
    }

    #[Test]
    public function anEventInAMonthWithNoPartitionIsRefused(): void
    {
        $monthWithNoPartition = '2035-01-15 10:00:00+00';
        self::assertNull($this->migrator()->fetchOne('SELECT to_regclass(?)', ['public.'.TicketEventPartitions::partitionNameFor(new \DateTimeImmutable($monthWithNoPartition))]));

        $this->assertRefused(
            '23514',
            'no partition of relation "tkt_ticket_event" found for row',
            fn (): int|string => $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
                self::INSERT,
                [self::SMUGGLED_EVENT, self::ACME_FOODS, self::TICKET, self::LENA, $monthWithNoPartition],
            )),
        );
    }

    private function addThePartitionOfTheMonth(): void
    {
        $month = new \DateTimeImmutable(self::AT);
        if (null !== $this->migrator()->fetchOne('SELECT to_regclass(?)', ['public.'.TicketEventPartitions::partitionNameFor($month)])) {
            return;
        }
        $this->migrator()->transactional(static function (Connection $migrator) use ($month): void {
            foreach (TicketEventPartitions::statementsFor($month) as $statement) {
                $migrator->executeStatement($statement);
            }
        });
        $this->hasAddedThePartition = true;
    }

    private function assertRefused(string $sqlState, string $messagePart, \Closure $work): void
    {
        try {
            $work();
            self::fail(\sprintf('Not refused: %s', $messagePart));
        } catch (DriverException $refusal) {
            self::assertSame($sqlState, $refusal->getSQLState());
            self::assertStringContainsString($messagePart, $refusal->getMessage());
        }
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function asMigratorUnder(string $organizationId, \Closure $work): mixed
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId($organizationId));
        $this->migrator()->beginTransaction();
        try {
            return $work($this->migrator());
        } finally {
            $this->migrator()->rollBack();
            $tenantContext->clear();
        }
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

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function rows(): TicketRows
    {
        return new TicketRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }
}

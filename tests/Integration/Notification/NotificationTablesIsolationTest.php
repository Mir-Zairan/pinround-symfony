<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Row-level security on every ntf_ table (Version20261114130000, ADR-0005) as pinround_app: each organization sees only
 * its own rows, none without a tenant, and cannot add one in the name of another organization. Removing a ticket removes
 * its pings, notifications and mutes by the ON DELETE CASCADE keys; deliveries have no ticket and stay. The
 * notifications sit in March 2027, a month whose partition the migration always creates.
 */
final class NotificationTablesIsolationTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000004131';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-000000004132';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-000000004133';
    private const string ACME_TICKET = '0197a3c0-0000-7000-8000-000000004134';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-000000004135';
    private const string LENA = '0197a3c0-0000-7000-8000-000000004136';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000004137';
    private const string MIA = '0197a3c0-0000-7000-8000-000000004138';
    private const string ACME_PING = '0197a3c0-0000-7000-8000-000000004141';
    private const string OTHER_PING = '0197a3c0-0000-7000-8000-000000004142';
    private const string SMUGGLED_PING = '0197a3c0-0000-7000-8000-000000004143';
    private const string ACME_NOTIFICATION = '0197a3c0-0000-7000-8000-000000004151';
    private const string OTHER_NOTIFICATION = '0197a3c0-0000-7000-8000-000000004152';
    private const string SMUGGLED_NOTIFICATION = '0197a3c0-0000-7000-8000-000000004153';
    private const string ACME_WINDOW = 'digest:2027-03-15';
    private const string OTHER_WINDOW = 'digest:2027-03-16';
    private const string SMUGGLED_WINDOW = 'digest:2027-03-17';
    // The column that tells one test row of the table from another.
    private const array KEY_COLUMNS = ['ntf_ping' => 'id', 'ntf_notification' => 'id', 'ntf_mute' => 'user_id', 'ntf_delivery' => 'window_key'];

    protected function setUp(): void
    {
        $this->removeRows();
        $this->ticketRows()->addOf(self::ACME_FOODS, self::ACME_TICKET, self::SHOP_PROJECT, self::LENA);
        $this->ticketRows()->addOf(self::OTHER_ORGANIZATION, self::OTHER_TICKET, self::SHOP_PROJECT, self::OMAR);
        foreach (self::tables() as [$table, $acmeKey, $otherKey]) {
            $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->insert($table, self::ACME_FOODS, $acmeKey, self::ACME_TICKET));
            $this->transactions()->committedAs(self::OTHER_ORGANIZATION, fn (): int|string => $this->insert($table, self::OTHER_ORGANIZATION, $otherKey, self::OTHER_TICKET));
        }
    }

    protected function tearDown(): void
    {
        $this->removeRows();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, string, string}> the table, then the key of Acme Foods' row, of the
     *                                                                 other organization's row and of the smuggled row
     */
    public static function tables(): iterable
    {
        yield 'ntf_ping' => ['ntf_ping', self::ACME_PING, self::OTHER_PING, self::SMUGGLED_PING];
        yield 'ntf_notification' => ['ntf_notification', self::ACME_NOTIFICATION, self::OTHER_NOTIFICATION, self::SMUGGLED_NOTIFICATION];
        yield 'ntf_mute' => ['ntf_mute', self::LENA, self::OMAR, self::MIA];
        yield 'ntf_delivery' => ['ntf_delivery', self::ACME_WINDOW, self::OTHER_WINDOW, self::SMUGGLED_WINDOW];
    }

    #[Test]
    public function theCasesCoverEveryNotificationTableInTheSchema(): void
    {
        self::assertEqualsCanonicalizing(
            array_keys(self::KEY_COLUMNS),
            $this->connection()->fetchFirstColumn("SELECT relname FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind IN ('r', 'p') AND NOT relispartition AND relname LIKE 'ntf\\_%'"),
        );
    }

    #[Test]
    #[DataProvider('tables')]
    public function eachOrganizationSeesOnlyItsOwnRowsAndNoneWithoutATenant(string $table, string $acmeKey, string $otherKey, string $smuggledKey): void
    {
        self::assertSame([$acmeKey], $this->keysAs(self::ACME_FOODS, $table));
        self::assertSame([$otherKey], $this->keysAs(self::OTHER_ORGANIZATION, $table));
        self::assertSame(0, $this->connection()->fetchOne(\sprintf('SELECT count(*) FROM %s', $table)));
    }

    #[Test]
    #[DataProvider('tables')]
    public function anotherOrganizationCannotAddARowInTheNameOfTheOrganization(string $table, string $acmeKey, string $otherKey, string $smuggledKey): void
    {
        try {
            $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): int|string => $this->insert($table, self::ACME_FOODS, $smuggledKey, self::ACME_TICKET));
            self::fail(\sprintf('Row-level security did not refuse an insert into %s.', $table));
        } catch (DriverException $refusal) {
            self::assertSame('42501', $refusal->getSQLState());
            self::assertStringContainsString(\sprintf('new row violates row-level security policy for table "%s"', $table), $refusal->getMessage());
        }

        self::assertSame([$acmeKey], $this->keysAs(self::ACME_FOODS, $table));
    }

    #[Test]
    public function removingATicketRemovesItsPingsNotificationsAndMutesButNotTheDeliveries(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);

        self::assertSame([], $this->keysAs(self::ACME_FOODS, 'ntf_ping'));
        self::assertSame([], $this->keysAs(self::ACME_FOODS, 'ntf_notification'));
        self::assertSame([], $this->keysAs(self::ACME_FOODS, 'ntf_mute'));
        self::assertSame([self::ACME_WINDOW], $this->keysAs(self::ACME_FOODS, 'ntf_delivery'));
        foreach (self::tables() as [$table, , $otherKey]) {
            self::assertSame([$otherKey], $this->keysAs(self::OTHER_ORGANIZATION, $table), 'Another organization\'s row went with the ticket too.');
        }
    }

    private function insert(string $table, string $organizationId, string $key, string $ticketId): int|string
    {
        return match ($table) {
            'ntf_ping' => $this->connection()->executeStatement(
                "INSERT INTO ntf_ping (organization_id, id, ticket_id, from_id, to_id, body, created_at) VALUES (?, ?, ?, ?, ?, 'Can you look at this today?', '2027-03-15 10:00:00+00')",
                [$organizationId, $key, $ticketId, self::LENA, self::OMAR],
            ),
            'ntf_notification' => $this->connection()->executeStatement(
                "INSERT INTO ntf_notification (organization_id, id, user_id, kind, actor_id, ticket_id, data, created_at) VALUES (?, ?, ?, 'pinged', ?, ?, '{}', '2027-03-15 10:00:00+00')",
                [$organizationId, $key, self::OMAR, self::LENA, $ticketId],
            ),
            'ntf_mute' => $this->connection()->executeStatement(
                "INSERT INTO ntf_mute (organization_id, user_id, ticket_id, created_at) VALUES (?, ?, ?, '2027-03-15 10:00:00+00')",
                [$organizationId, $key, $ticketId],
            ),
            'ntf_delivery' => $this->connection()->executeStatement(
                "INSERT INTO ntf_delivery (organization_id, user_id, window_key, sent_at) VALUES (?, ?, ?, '2027-03-15 10:00:00+00')",
                [$organizationId, self::OMAR, $key],
            ),
            default => throw new \LogicException(\sprintf('No test row for %s.', $table)),
        };
    }

    /**
     * @return list<mixed>
     */
    private function keysAs(string $organizationId, string $table): array
    {
        $column = self::KEY_COLUMNS[$table];

        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchFirstColumn(\sprintf('SELECT %1$s FROM %2$s ORDER BY %1$s', $column, $table)));
    }

    private function removeRows(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
        new DeliveryRows($this->migrator(), self::getContainer()->get(TenantContext::class))->removeAllOf(self::ACME_FOODS, self::OTHER_ORGANIZATION);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }
}

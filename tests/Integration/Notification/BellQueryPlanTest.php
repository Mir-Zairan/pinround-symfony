<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\Api\NotificationQueries;
use App\Notification\Infrastructure\Doctrine\NotificationPartitions;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The plans of the bell's reads over 50,000 notifications of 500 people in one month, as pinround_app under row-level
 * security: the tickets of Omar's open rows and the bell itself read the month's partition through its bell index
 * (organization_id, user_id, actor_id, ticket_id, created_at DESC) WHERE done_at IS NULL, never a sequential scan
 * (performance-and-scale.md). Four of every five rows are done, as in a bell people keep tidy; Omar's 100 are all open,
 * on 300 tickets everyone may see.
 */
final class BellQueryPlanTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string NOW = '2027-03-20 10:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8006-0000000000a1';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8006-0000000000c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8006-0000000000d1';
    private const string LENA = '0197a3c0-0000-7000-8006-0000000000b1';
    private const string OMAR = '0197a3c0-0000-7000-8006-000000000000';
    private const int TICKET_COUNT = 300;
    private const int NOTIFICATION_COUNT = 50_000;
    private const int PEOPLE_COUNT = 500;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::removeRows();
        self::migrator()->executeStatement('VACUUM ntf_notification');
        self::shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
        ]);
        $notificationRows = new NotificationRows(self::migrator(), self::tenantContext());
        $notificationRows->executeOf(
            self::ACME_FOODS,
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
             SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8007-' || lpad(to_hex(number), 12, '0') AS uuid), CAST(? AS uuid), CAST(? AS uuid),
                 number, 'SHOP-' || number, 'Checkout button is hidden', '', 'bug', 'normal', 3, CAST(? AS uuid), CAST(? AS uuid), 'all', '{}', '{}',
                 TIMESTAMPTZ '2027-03-01 08:00:00+00', TIMESTAMPTZ '2027-03-01 08:00:00+00'
             FROM generate_series(1, ?) AS number",
            [self::ACME_FOODS, self::SHOP_PROJECT, self::SHOP_TEAM, ShopTeamRows::TO_DO_STEP, self::LENA, self::TICKET_COUNT],
        );
        // Person number % 500 gets notification number, about ticket number / 7 % 300 + 1; person 0 is Omar.
        $notificationRows->executeOf(
            self::ACME_FOODS,
            "INSERT INTO ntf_notification (organization_id, id, user_id, kind, actor_id, ticket_id, data, created_at, done_at)
             SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8009-' || lpad(to_hex(number), 12, '0') AS uuid),
                 CASE WHEN number % ? = 0 THEN CAST(? AS uuid) ELSE CAST('0197a3c0-0000-7000-8008-' || lpad(to_hex(number % ?), 12, '0') AS uuid) END,
                 'question', CAST(? AS uuid), CAST('0197a3c0-0000-7000-8007-' || lpad(to_hex(number / 7 % ? + 1), 12, '0') AS uuid),
                 jsonb_build_object('actorName', 'Lena', 'ticketKey', 'SHOP-' || (number / 7 % ? + 1)),
                 TIMESTAMPTZ '2027-03-01 08:00:00+00' + number * INTERVAL '30 seconds',
                 CASE WHEN number % 5 = 0 THEN NULL ELSE TIMESTAMPTZ '2027-03-19 08:00:00+00' END
             FROM generate_series(1, ?) AS number",
            [
                self::ACME_FOODS, self::PEOPLE_COUNT, self::OMAR, self::PEOPLE_COUNT, self::LENA, self::TICKET_COUNT, self::TICKET_COUNT,
                self::NOTIFICATION_COUNT,
            ],
        );
        self::migrator()->executeStatement('ANALYZE ntf_notification, tkt_ticket');
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeRows();
        self::migrator()->executeStatement('ANALYZE ntf_notification, tkt_ticket');
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::tenantContext()->reset();
        parent::tearDown();
    }

    #[Test]
    public function theTicketsOfTheOpenRowsAreReadThroughTheBellIndex(): void
    {
        $this->assertReadsTheMonthThroughTheBellIndex($this->planOf('SELECT ticket_id FROM ntf_notification WHERE'));
    }

    #[Test]
    public function theBellIsReadThroughTheBellIndex(): void
    {
        $this->assertReadsTheMonthThroughTheBellIndex($this->planOf('FROM ntf_notification AS bell'));
    }

    private function assertReadsTheMonthThroughTheBellIndex(string $plan): void
    {
        $partition = NotificationPartitions::partitionNameFor(new \DateTimeImmutable(self::NOW));
        $bellIndex = $this->bellIndexOf($partition);

        self::assertMatchesRegularExpression(
            \sprintf('/(Index Scan|Index Only Scan) (Backward )?using %1$s on %2$s|Bitmap Index Scan on %1$s/', preg_quote($bellIndex, '/'), preg_quote($partition, '/')),
            $plan,
            $plan,
        );
        self::assertStringNotContainsString('Seq Scan on '.$partition, $plan, $plan);
    }

    /**
     * The month's partition's copy of the bell index; PostgreSQL names it after the partition.
     */
    private function bellIndexOf(string $partition): string
    {
        $indexName = self::migrator()->fetchOne(
            "SELECT index_class.relname FROM pg_inherits AS inheritance
             JOIN pg_index AS partition_index ON partition_index.indexrelid = inheritance.inhrelid
             JOIN pg_class AS index_class ON index_class.oid = inheritance.inhrelid
             WHERE inheritance.inhparent = 'ntf_notification_user_id_actor_id_ticket_id_created_at_idx'::regclass
                 AND partition_index.indrelid = CAST(? AS regclass)",
            [$partition],
        );
        self::assertIsString($indexName, 'The month\'s partition has no bell index.');

        return $indexName;
    }

    /**
     * Omar's bell read as his page reads it, as pinround_app under Acme Foods, and the captured query that contains
     * $sqlPart explained in the same transaction with the parameters and types it was sent with.
     */
    private function planOf(string $sqlPart): string
    {
        $debugData = $this->debugData();

        return $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($debugData, $sqlPart): string {
            $connection = $this->connection();
            self::assertSame(['pinround_app', 'on'], [$connection->fetchOne('SELECT current_user'), $connection->fetchOne('SHOW row_security')]);
            $debugData->reset();
            $bell = $this->notificationQueries()->bell(new UserId(self::OMAR));
            self::assertSame(100, $bell->openCount, 'Omar\'s bell is not the one seeded, so its plan proves little.');
            [$sql, $parameters, $types] = self::queryOf($debugData, $sqlPart);

            $planLines = $connection->fetchFirstColumn('EXPLAIN '.$sql, $parameters, $types);

            return implode("\n", array_map(static fn (mixed $line): string => \is_string($line) ? $line : '', $planLines));
        });
    }

    /**
     * The captured query that contains $sqlPart; DBAL expanded its array parameters before the debug middleware
     * recorded it, so the SQL, parameters and types run as they are.
     *
     * @return array{string, array<int<0, max>|string, mixed>, array<int<0, max>|string, ArrayParameterType|ParameterType|Type|string>}
     */
    private static function queryOf(DebugDataHolder $debugData, string $sqlPart): array
    {
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            self::assertIsArray($queriesOfOneConnection);
            foreach ($queriesOfOneConnection as $query) {
                self::assertIsArray($query);
                if (\is_string($query['sql'] ?? null) && str_contains($query['sql'], $sqlPart)) {
                    self::assertIsArray($query['params']);
                    self::assertIsArray($query['types']);

                    /** @var array<int<0, max>|string, mixed> $parameters */
                    $parameters = $query['params'];

                    /** @var array<int<0, max>|string, ArrayParameterType|ParameterType|Type|string> $types */
                    $types = $query['types'];

                    return [$query['sql'], $parameters, $types];
                }
            }
        }
        self::fail(\sprintf('The bell sent no query with "%s".', $sqlPart));
    }

    private function notificationQueries(): NotificationQueries
    {
        $notificationQueries = self::getContainer()->get('test.notification_queries');
        \assert($notificationQueries instanceof NotificationQueries);

        return $notificationQueries;
    }

    private static function removeRows(): void
    {
        self::shopTeamRows()->removeAllOf([self::LENA, self::OMAR], self::ACME_FOODS);
    }

    private static function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::migrator(), self::tenantContext());
    }

    private static function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::tenantContext());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private static function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * digest_recipients (Version20261115150000) called as pinround_app with no tenant, as the digest job calls it, on
 * 3 March 2027 at 10:00. Omar and Lena in Acme Foods and Omar in Beta Bakery each have a ping or question due in a
 * digest; every row of Mia's is one the digest must leave out: read, done, held, under an hour old, of the answer and
 * reply kinds, or about no ticket. Other tests' rows may sit in the table, so only these organizations' pairs are read.
 */
final class DigestRecipientsFunctionTest extends KernelTestCase
{
    private const string NOW = '2027-03-03 10:00:00+00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000d2101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000d2102';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000d2111';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000d2112';
    private const string SHOP_TICKET = '0197a3c0-0000-7000-8000-0000000d2121';
    private const string BAKERY_TICKET = '0197a3c0-0000-7000-8000-0000000d2122';
    // In id order: Lena, then Mia, then Omar.
    private const string LENA = '0197a3c0-0000-7000-8000-0000000d2131';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000d2132';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000d2133';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->ticketRows()->addOf(self::ACME_FOODS, self::SHOP_TICKET, self::SHOP_PROJECT, self::LENA);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BAKERY_TICKET, self::BAKERY_PROJECT, self::LENA);
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_notification', [
            self::notification('2201', self::OMAR, 'ping', '2027-03-03 08:59:00+00'),
            self::notification('2202', self::OMAR, 'question', '2027-03-03 07:00:00+00'),
            // Exactly an hour old, and released at the very moment.
            self::notification('2203', self::LENA, 'ping', '2027-03-03 09:00:00+00', ['deliver_after' => self::NOW]),
            self::notification('2211', self::MIA, 'ping', '2027-03-03 07:00:00+00', ['read_at' => '2027-03-03 07:05:00+00']),
            self::notification('2212', self::MIA, 'question', '2027-03-03 07:00:00+00', ['done_at' => '2027-03-03 07:05:00+00']),
            self::notification('2213', self::MIA, 'ping', '2027-03-03 07:00:00+00', ['deliver_after' => '2027-03-03 10:00:01+00']),
            self::notification('2214', self::MIA, 'ping', '2027-03-03 09:00:01+00'),
            self::notification('2215', self::MIA, 'answer', '2027-03-03 07:00:00+00'),
            self::notification('2216', self::MIA, 'reply', '2027-03-03 07:00:00+00'),
            self::notification('2217', self::MIA, 'ping', '2027-03-03 07:00:00+00', ['ticket_id' => null]),
        ]);
        $this->notificationRows()->addOf(self::BETA_BAKERY, 'ntf_notification', [
            [...self::notification('2221', self::OMAR, 'question', '2027-03-03 05:00:00+00'), 'organization_id' => self::BETA_BAKERY, 'ticket_id' => self::BAKERY_TICKET],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function itReturnsEachPersonWithAPingOrQuestionDueInADigestOncePerOrganization(): void
    {
        self::assertSame([
            [self::ACME_FOODS, self::LENA],
            [self::ACME_FOODS, self::OMAR],
            [self::BETA_BAKERY, self::OMAR],
        ], $this->recipientsAt(self::NOW));
    }

    /**
     * At 09:30 Lena's ping and Omar's 08:59 ping are under an hour old, and Lena's is still held; Omar's 07:00
     * question still counts. The function goes by p_now, never by the clock of the database.
     */
    #[Test]
    public function itGoesByTheTimeItIsGiven(): void
    {
        self::assertSame([
            [self::ACME_FOODS, self::OMAR],
            [self::BETA_BAKERY, self::OMAR],
        ], $this->recipientsAt('2027-03-03 09:30:00+00'));
        self::assertSame([], $this->recipientsAt('2027-03-03 05:59:59+00'));
    }

    /**
     * The test month holds a handful of rows, for which a sequential scan is cheaper than any index; with it off, the
     * body explained as pinround_resolver reads the month's copy of the digest index.
     */
    #[Test]
    public function itReadsTheDigestIndex(): void
    {
        $migrator = $this->migrator();
        $digestIndex = $migrator->fetchOne(
            "SELECT index_class.relname FROM pg_inherits AS inheritance
             JOIN pg_index AS partition_index ON partition_index.indexrelid = inheritance.inhrelid
             JOIN pg_class AS index_class ON index_class.oid = inheritance.inhrelid
             WHERE inheritance.inhparent = 'ntf_notification_user_id_created_at_digest_idx'::regclass
                 AND partition_index.indrelid = 'ntf_notification_202703'::regclass",
        );
        self::assertIsString($digestIndex, 'The month\'s partition has no digest index.');

        $migrator->beginTransaction();
        try {
            $migrator->executeStatement('SET LOCAL ROLE pinround_resolver');
            $migrator->executeStatement('SET LOCAL enable_seqscan = off');
            $body = $migrator->fetchOne(
                "SELECT prosrc FROM pg_proc WHERE oid = 'public.digest_recipients(timestamptz)'::regprocedure",
            );
            self::assertIsString($body);
            $plan = implode("\n", array_map(
                static fn (mixed $line): string => \is_string($line) ? $line : '',
                $migrator->fetchFirstColumn('EXPLAIN '.str_replace('p_now', "'".self::NOW."'::timestamptz", $body)),
            ));
        } finally {
            $migrator->rollBack();
        }

        self::assertMatchesRegularExpression(
            \sprintf('/(Index Only Scan|Index Scan|Bitmap Index Scan) (using |on )%s/', preg_quote($digestIndex, '/')),
            $plan,
            $plan,
        );
        self::assertStringNotContainsString('Seq Scan on ntf_notification_202703', $plan, $plan);
    }

    /**
     * @return list<array{string, string}> each pair's organization and person, as the function orders them
     */
    private function recipientsAt(string $now): array
    {
        $connection = $this->connection();
        self::assertFalse($connection->isTransactionActive());
        self::assertContains($connection->fetchOne("SELECT current_setting('app.org_id', true)"), [null, '']);

        $rows = $connection->fetchAllNumeric(
            'SELECT organization_id::text, user_id::text FROM digest_recipients(CAST(? AS timestamptz)) WHERE organization_id IN (?, ?)',
            [$now, self::ACME_FOODS, self::BETA_BAKERY],
        );

        return array_map(static function (array $row): array {
            [$organizationId, $userId] = $row;
            self::assertIsString($organizationId);
            self::assertIsString($userId);

            return [$organizationId, $userId];
        }, $rows);
    }

    /**
     * @param array<string, string|null> $change the columns that differ from an open, unread one about SHOP's ticket
     *
     * @return array<string, mixed>
     */
    private static function notification(string $idSuffix, string $userId, string $kind, string $createdAt, array $change = []): array
    {
        return [
            'organization_id' => self::ACME_FOODS,
            'id' => '0197a3c0-0000-7000-8000-00000000'.$idSuffix,
            'user_id' => $userId,
            'kind' => $kind,
            'actor_id' => self::LENA,
            'ticket_id' => self::SHOP_TICKET,
            'data' => json_encode(['actorName' => 'Lena'], \JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
            ...$change,
        ];
    }

    private function removeRows(): void
    {
        // The row about no ticket has no ON DELETE CASCADE to go with; the migrator owns the table, so it may delete.
        $this->notificationRows()->executeOf(self::ACME_FOODS, 'DELETE FROM ntf_notification WHERE organization_id = ? AND ticket_id IS NULL', [self::ACME_FOODS]);
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function notificationRows(): NotificationRows
    {
        return new NotificationRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }
}

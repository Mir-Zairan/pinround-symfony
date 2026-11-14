<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Application\AutoClear;

use App\Notification\Application\AutoClear\ClearPingsOnAction;
use App\Organization\Domain\OrgSettings;
use App\Organization\Infrastructure\Doctrine\DoctrineOrgSettingsStore;
use App\SharedKernel\Application\PingClearing;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The PingClearing port as Ticketing and Conversation get it, on committed rows, as pinround_app in a transaction under
 * a tenant. Beta Bakery holds the same ticket, ping, person and notification ids as Acme Foods. Other tests write to the
 * same outbox at the same time, so outbox rows are counted by this test's own ping ids.
 */
final class ClearPingsOnActionTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c6a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000c6a02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000c6a03';
    private const string TICKET_A = '0197a3c0-0000-7000-8000-0000000c6a04';
    private const string TICKET_B = '0197a3c0-0000-7000-8000-0000000c6a05';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c6a06';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c6a07';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000c6a08';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000c6a09';

    private const string FIRST_PING_TO_OMAR = '0197a3c0-0000-7000-8000-0000000c6b01';
    private const string SECOND_PING_TO_OMAR = '0197a3c0-0000-7000-8000-0000000c6b02';
    private const string PING_TO_MIA = '0197a3c0-0000-7000-8000-0000000c6b03';
    private const string PING_TO_OMAR_ON_TICKET_B = '0197a3c0-0000-7000-8000-0000000c6b04';
    private const string CLEARED_PING_TO_OMAR = '0197a3c0-0000-7000-8000-0000000c6b05';

    private const string FIRST_BELL_ROW = '0197a3c0-0000-7000-8000-0000000c6c01';
    private const string SECOND_BELL_ROW = '0197a3c0-0000-7000-8000-0000000c6c02';
    private const string MIAS_BELL_ROW = '0197a3c0-0000-7000-8000-0000000c6c03';
    private const string TICKET_B_BELL_ROW = '0197a3c0-0000-7000-8000-0000000c6c04';
    private const string REPLY_BELL_ROW = '0197a3c0-0000-7000-8000-0000000c6c05';

    private const string ACTED_AT = '2027-03-16 09:00:00+00:00';
    private const string ACTED_AGAIN_AT = '2027-03-16 10:00:00+00:00';

    private ?SecondSession $secondSession = null;

    protected function setUp(): void
    {
        $this->removeRows();
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->ticketRows()->addOf($organizationId, self::TICKET_A, self::SHOP_PROJECT, self::LENA, 1);
            $this->ticketRows()->addOf($organizationId, self::TICKET_B, self::SHOP_PROJECT, self::LENA, 2);
            $this->notificationRows()->addOf($organizationId, 'ntf_ping', [
                self::ping($organizationId, self::SECOND_PING_TO_OMAR, self::OMAR, self::TICKET_A, '2027-03-15 11:00:00+00'),
                self::ping($organizationId, self::FIRST_PING_TO_OMAR, self::OMAR, self::TICKET_A, '2027-03-15 10:00:00+00'),
                self::ping($organizationId, self::PING_TO_MIA, self::MIA, self::TICKET_A, '2027-03-15 10:30:00+00'),
                self::ping($organizationId, self::PING_TO_OMAR_ON_TICKET_B, self::OMAR, self::TICKET_B, '2027-03-15 10:00:00+00'),
                [
                    ...self::ping($organizationId, self::CLEARED_PING_TO_OMAR, self::OMAR, self::TICKET_A, '2027-03-15 09:00:00+00'),
                    'cleared_at' => '2027-03-15 09:30:00+00',
                    'cleared_reason' => 'done',
                ],
            ]);
            $this->notificationRows()->addOf($organizationId, 'ntf_notification', [
                self::bellRow($organizationId, self::FIRST_BELL_ROW, self::OMAR, self::TICKET_A, 'ping', ['read_at' => '2027-03-15 10:15:00+00']),
                self::bellRow($organizationId, self::SECOND_BELL_ROW, self::OMAR, self::TICKET_A, 'ping'),
                self::bellRow($organizationId, self::MIAS_BELL_ROW, self::MIA, self::TICKET_A, 'ping'),
                self::bellRow($organizationId, self::TICKET_B_BELL_ROW, self::OMAR, self::TICKET_B, 'ping'),
                self::bellRow($organizationId, self::REPLY_BELL_ROW, self::OMAR, self::TICKET_A, 'reply'),
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->secondSession?->entityManager->getConnection()->close();
        $this->secondSession = null;
        self::getContainer()->get('doctrine')->resetManager();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function thePortIsClearPingsOnAction(): void
    {
        self::assertInstanceOf(ClearPingsOnAction::class, self::getContainer()->get('test.shared_kernel_ping_clearing'));
    }

    #[Test]
    public function actingClearsOnlyTheActorsOpenPingsOnThatTicketWithTheirBellRowsAndOneEventEach(): void
    {
        $outboxRowsBefore = $this->outboxRowsByPing();

        $clearedCount = $this->clearAsOmar(self::ACTED_AT);

        self::assertSame(2, $clearedCount);
        self::assertSame([
            self::FIRST_PING_TO_OMAR => ['acted', '2027-03-16 09:00:00'],
            self::SECOND_PING_TO_OMAR => ['acted', '2027-03-16 09:00:00'],
            self::PING_TO_MIA => [null, null],
            self::PING_TO_OMAR_ON_TICKET_B => [null, null],
            self::CLEARED_PING_TO_OMAR => ['done', '2027-03-15 09:30:00'],
        ], $this->pingStatesOf(self::ACME_FOODS));
        self::assertSame([
            self::FIRST_BELL_ROW => '2027-03-16 09:00:00',
            self::SECOND_BELL_ROW => '2027-03-16 09:00:00',
            self::MIAS_BELL_ROW => null,
            self::TICKET_B_BELL_ROW => null,
            self::REPLY_BELL_ROW => null,
        ], $this->bellRowsDoneAtOf(self::ACME_FOODS));
        self::assertSame([
            self::FIRST_PING_TO_OMAR => $outboxRowsBefore[self::FIRST_PING_TO_OMAR] + 1,
            self::SECOND_PING_TO_OMAR => $outboxRowsBefore[self::SECOND_PING_TO_OMAR] + 1,
            self::PING_TO_MIA => $outboxRowsBefore[self::PING_TO_MIA],
            self::PING_TO_OMAR_ON_TICKET_B => $outboxRowsBefore[self::PING_TO_OMAR_ON_TICKET_B],
            self::CLEARED_PING_TO_OMAR => $outboxRowsBefore[self::CLEARED_PING_TO_OMAR],
        ], $this->outboxRowsByPing());
        $this->assertNothingChangedIn(self::BETA_BAKERY);
    }

    #[Test]
    public function aSecondActionClearsNoneAndWritesNoEvent(): void
    {
        self::assertSame(2, $this->clearAsOmar(self::ACTED_AT));
        $outboxRowsAfterFirstAction = $this->outboxRowsByPing();

        self::assertSame(0, $this->clearAsOmar(self::ACTED_AGAIN_AT));

        self::assertSame($outboxRowsAfterFirstAction, $this->outboxRowsByPing());
        self::assertSame(['acted', '2027-03-16 09:00:00'], $this->pingStatesOf(self::ACME_FOODS)[self::FIRST_PING_TO_OMAR]);
        self::assertSame('2027-03-16 09:00:00', $this->bellRowsDoneAtOf(self::ACME_FOODS)[self::FIRST_BELL_ROW]);
    }

    #[Test]
    public function withAutoClearOffActingClearsNothing(): void
    {
        $this->storeAutoClear(self::ACME_FOODS, false);
        $outboxRowsBefore = $this->outboxRowsByPing();

        self::assertSame(0, $this->clearAsOmar(self::ACTED_AT));

        $this->assertNothingChangedIn(self::ACME_FOODS);
        self::assertSame($outboxRowsBefore, $this->outboxRowsByPing());
    }

    #[Test]
    public function clearingWaitsForAnotherSessionThatHoldsThePings(): void
    {
        // FOR KEY SHARE lets the clearing UPDATE through but not a FOR UPDATE, so only the lock taken before reading waits.
        $sqlState = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): string {
            $lockHolder = $this->secondSession()->entityManager->getConnection();
            $lockHolder->beginTransaction();
            try {
                self::assertSame(
                    [self::FIRST_PING_TO_OMAR, self::SECOND_PING_TO_OMAR],
                    $lockHolder->fetchFirstColumn(
                        'SELECT id FROM ntf_ping WHERE to_id = ? AND ticket_id = ? AND cleared_at IS NULL ORDER BY created_at FOR KEY SHARE',
                        [self::OMAR, self::TICKET_A],
                    ),
                );
                $this->connection()->executeStatement("SET LOCAL lock_timeout = '300ms'");
                try {
                    $this->pingClearing()->clearForActor(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET_A), new UserId(self::OMAR), new \DateTimeImmutable(self::ACTED_AT));
                } catch (DriverException $failure) {
                    return (string) $failure->getSQLState();
                }
                self::fail('The pings were cleared while another session held them.');
            } finally {
                $lockHolder->rollBack();
            }
        });

        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $sqlState);
        $this->assertNothingChangedIn(self::ACME_FOODS);
    }

    private function clearAsOmar(string $at): int
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->pingClearing()->clearForActor(
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET_A),
            new UserId(self::OMAR),
            new \DateTimeImmutable($at),
        ));
    }

    private function assertNothingChangedIn(string $organizationId): void
    {
        self::assertSame([
            self::FIRST_PING_TO_OMAR => [null, null],
            self::SECOND_PING_TO_OMAR => [null, null],
            self::PING_TO_MIA => [null, null],
            self::PING_TO_OMAR_ON_TICKET_B => [null, null],
            self::CLEARED_PING_TO_OMAR => ['done', '2027-03-15 09:30:00'],
        ], $this->pingStatesOf($organizationId));
        self::assertSame([
            self::FIRST_BELL_ROW => null,
            self::SECOND_BELL_ROW => null,
            self::MIAS_BELL_ROW => null,
            self::TICKET_B_BELL_ROW => null,
            self::REPLY_BELL_ROW => null,
        ], $this->bellRowsDoneAtOf($organizationId));
    }

    /**
     * @return array<string, array{?string, ?string}> the cleared reason and the UTC cleared time by ping id
     */
    private function pingStatesOf(string $organizationId): array
    {
        $rows = $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllAssociative(
            "SELECT id, cleared_reason, to_char(cleared_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS cleared_at FROM ntf_ping ORDER BY id",
        ));
        self::assertNotSame([], $rows);

        $states = [];
        foreach ($rows as $row) {
            self::assertIsString($row['id']);
            self::assertTrue(null === $row['cleared_reason'] || \is_string($row['cleared_reason']));
            self::assertTrue(null === $row['cleared_at'] || \is_string($row['cleared_at']));
            $states[$row['id']] = [$row['cleared_reason'], $row['cleared_at']];
        }

        return $states;
    }

    /**
     * @return array<string, ?string> the UTC done time by notification id
     */
    private function bellRowsDoneAtOf(string $organizationId): array
    {
        $rows = $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllAssociative(
            "SELECT id, to_char(done_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS done_at FROM ntf_notification ORDER BY id",
        ));
        self::assertNotSame([], $rows);

        $doneAtById = [];
        foreach ($rows as $row) {
            self::assertIsString($row['id']);
            self::assertTrue(null === $row['done_at'] || \is_string($row['done_at']));
            $doneAtById[$row['id']] = $row['done_at'];
        }

        return $doneAtById;
    }

    /**
     * @return array<string, int> outbox rows naming each ping id of this test
     */
    private function outboxRowsByPing(): array
    {
        $rowsByPing = array_fill_keys([self::FIRST_PING_TO_OMAR, self::SECOND_PING_TO_OMAR, self::PING_TO_MIA, self::PING_TO_OMAR_ON_TICKET_B, self::CLEARED_PING_TO_OMAR], 0);
        foreach ($this->connection()->fetchFirstColumn("SELECT body FROM messenger_outbox WHERE queue_name = 'outbox'") as $body) {
            self::assertIsString($body);
            foreach (array_keys($rowsByPing) as $pingId) {
                if (str_contains($body, $pingId)) {
                    ++$rowsByPing[$pingId];
                }
            }
        }

        return $rowsByPing;
    }

    private function storeAutoClear(string $organizationId, bool $isAutoClearOn): void
    {
        $this->transactions()->committedAs($organizationId, function () use ($organizationId, $isAutoClearOn): void {
            new DoctrineOrgSettingsStore($this->entityManager())->add(
                new OrgSettings(new OrganizationId($organizationId), new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 08:00:00 UTC')),
            );
            $this->connection()->executeStatement(
                "UPDATE org_settings SET data = data || jsonb_build_object('auto_clear', ?::boolean)",
                [$isAutoClearOn ? 'true' : 'false'],
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function ping(string $organizationId, string $pingId, string $toId, string $ticketId, string $createdAt): array
    {
        return [
            'organization_id' => $organizationId,
            'id' => $pingId,
            'ticket_id' => $ticketId,
            'from_id' => self::LENA,
            'to_id' => $toId,
            'body' => 'Any news on this one?',
            'created_at' => $createdAt,
        ];
    }

    /**
     * An open bell row from Lena.
     *
     * @param array<string, string> $change the columns that differ from an open, unread one
     *
     * @return array<string, mixed>
     */
    private static function bellRow(string $organizationId, string $notificationId, string $userId, string $ticketId, string $kind, array $change = []): array
    {
        return [
            'organization_id' => $organizationId,
            'id' => $notificationId,
            'user_id' => $userId,
            'kind' => $kind,
            'actor_id' => self::LENA,
            'ticket_id' => $ticketId,
            'data' => '{}',
            'created_at' => '2027-03-15 10:00:00+00',
            ...$change,
        ];
    }

    private function removeRows(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function pingClearing(): PingClearing
    {
        $pingClearing = self::getContainer()->get('test.shared_kernel_ping_clearing');
        \assert($pingClearing instanceof PingClearing);

        return $pingClearing;
    }

    private function secondSession(): SecondSession
    {
        return $this->secondSession ??= SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function notificationRows(): NotificationRows
    {
        return new NotificationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}

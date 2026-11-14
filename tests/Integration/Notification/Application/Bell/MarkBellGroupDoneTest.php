<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Application\Bell;

use App\Notification\Application\Bell\MarkBellGroupDone;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketVisibility;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Done on Omar's bell group from Lena about the ticket, on committed rows, as pinround_app in a transaction under a
 * tenant. Next to Lena's two open pings to Omar sit a ping of hers still held by quiet hours, a ping from Mia to Omar
 * and one from Lena to Mia. Beta Bakery holds the same ids. Other tests write to the same outbox at the same time, so
 * outbox rows are counted by this test's own ping ids.
 */
final class MarkBellGroupDoneTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c7a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000c7a02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000c7a03';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000c7a04';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c7a06';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c7a07';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000c7a08';

    private const string FIRST_PING_FROM_LENA = '0197a3c0-0000-7000-8000-0000000c7b01';
    private const string SECOND_PING_FROM_LENA = '0197a3c0-0000-7000-8000-0000000c7b02';
    private const string HELD_PING_FROM_LENA = '0197a3c0-0000-7000-8000-0000000c7b03';
    private const string PING_FROM_MIA = '0197a3c0-0000-7000-8000-0000000c7b04';
    private const string PING_TO_MIA = '0197a3c0-0000-7000-8000-0000000c7b05';

    private const string FIRST_BELL_ROW = '0197a3c0-0000-7000-8000-0000000c7c01';
    private const string SECOND_BELL_ROW = '0197a3c0-0000-7000-8000-0000000c7c02';
    private const string MIAS_BELL_ROW_TO_OMAR = '0197a3c0-0000-7000-8000-0000000c7c03';
    private const string LENAS_BELL_ROW_TO_MIA = '0197a3c0-0000-7000-8000-0000000c7c04';

    private const string DONE_AT = '2027-03-16 09:00:00+00:00';

    protected function setUp(): void
    {
        $this->removeRows();
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $this->ticketRows()->addOf($organizationId, self::TICKET, self::SHOP_PROJECT, self::LENA, 1);
            $this->notificationRows()->addOf($organizationId, 'ntf_ping', [
                self::ping($organizationId, self::SECOND_PING_FROM_LENA, self::LENA, self::OMAR, null),
                self::ping($organizationId, self::FIRST_PING_FROM_LENA, self::LENA, self::OMAR, null),
                self::ping($organizationId, self::HELD_PING_FROM_LENA, self::LENA, self::OMAR, '2027-03-16 09:00:01+00'),
                self::ping($organizationId, self::PING_FROM_MIA, self::MIA, self::OMAR, null),
                self::ping($organizationId, self::PING_TO_MIA, self::LENA, self::MIA, null),
            ]);
            $this->notificationRows()->addOf($organizationId, 'ntf_notification', [
                self::bellRow($organizationId, self::FIRST_BELL_ROW, self::LENA, self::OMAR),
                self::bellRow($organizationId, self::SECOND_BELL_ROW, self::LENA, self::OMAR),
                self::bellRow($organizationId, self::MIAS_BELL_ROW_TO_OMAR, self::MIA, self::OMAR),
                self::bellRow($organizationId, self::LENAS_BELL_ROW_TO_MIA, self::LENA, self::MIA),
            ]);
        }
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function doneClearsTheSendersDeliveredPingsToTheViewerAsDoneAndWritesPingClearedDoneForEach(): void
    {
        $outboxBodiesBefore = $this->outboxBodiesByPing();

        self::assertSame(2, $this->markLenasGroupDoneAsOmar());

        self::assertSame([
            self::FIRST_PING_FROM_LENA => ['done', '2027-03-16 09:00:00'],
            self::SECOND_PING_FROM_LENA => ['done', '2027-03-16 09:00:00'],
            self::HELD_PING_FROM_LENA => [null, null],
            self::PING_FROM_MIA => [null, null],
            self::PING_TO_MIA => [null, null],
        ], $this->pingStatesOf(self::ACME_FOODS));
        self::assertSame([
            self::FIRST_BELL_ROW => '2027-03-16 09:00:00',
            self::SECOND_BELL_ROW => '2027-03-16 09:00:00',
            self::MIAS_BELL_ROW_TO_OMAR => null,
            self::LENAS_BELL_ROW_TO_MIA => null,
        ], $this->bellRowsDoneAtOf(self::ACME_FOODS));
        $newBodies = $this->newOutboxBodiesByPing($outboxBodiesBefore);
        self::assertSame([self::FIRST_PING_FROM_LENA, self::SECOND_PING_FROM_LENA], array_keys(array_filter($newBodies)));
        foreach ([self::FIRST_PING_FROM_LENA, self::SECOND_PING_FROM_LENA] as $pingId) {
            self::assertCount(1, $newBodies[$pingId]);
            self::assertStringContainsString('PingCleared', $newBodies[$pingId][0]);
            self::assertStringContainsString('PingClearedReason:Done', $newBodies[$pingId][0]);
        }
        $this->assertNothingChangedIn(self::BETA_BAKERY);
    }

    #[Test]
    public function aSecondDoneOfTheGroupMarksAndClearsNothingMore(): void
    {
        self::assertSame(2, $this->markLenasGroupDoneAsOmar());
        $outboxBodiesAfterFirstDone = $this->outboxBodiesByPing();
        $pingStatesAfterFirstDone = $this->pingStatesOf(self::ACME_FOODS);

        self::assertSame(0, $this->markLenasGroupDoneAsOmar());

        self::assertSame($pingStatesAfterFirstDone, $this->pingStatesOf(self::ACME_FOODS));
        self::assertSame($outboxBodiesAfterFirstDone, $this->outboxBodiesByPing());
    }

    private function markLenasGroupDoneAsOmar(): int
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->markBellGroupDone()->markDone(
            TicketVisibility::for(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), [], false, null),
            new UserId(self::LENA),
            new TicketId(self::TICKET),
            new \DateTimeImmutable(self::DONE_AT),
        ));
    }

    private function assertNothingChangedIn(string $organizationId): void
    {
        self::assertSame(array_fill_keys([self::FIRST_PING_FROM_LENA, self::SECOND_PING_FROM_LENA, self::HELD_PING_FROM_LENA, self::PING_FROM_MIA, self::PING_TO_MIA], [null, null]), $this->pingStatesOf($organizationId));
        self::assertSame(array_fill_keys([self::FIRST_BELL_ROW, self::SECOND_BELL_ROW, self::MIAS_BELL_ROW_TO_OMAR, self::LENAS_BELL_ROW_TO_MIA], null), $this->bellRowsDoneAtOf($organizationId));
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
     * @return array<string, list<string>> the outbox rows' bodies naming each ping id of this test, by ping id
     */
    private function outboxBodiesByPing(): array
    {
        $bodiesByPing = array_fill_keys([self::FIRST_PING_FROM_LENA, self::SECOND_PING_FROM_LENA, self::HELD_PING_FROM_LENA, self::PING_FROM_MIA, self::PING_TO_MIA], []);
        foreach ($this->connection()->fetchFirstColumn("SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' ORDER BY id") as $body) {
            self::assertIsString($body);
            foreach (array_keys($bodiesByPing) as $pingId) {
                if (str_contains($body, $pingId)) {
                    $bodiesByPing[$pingId][] = $body;
                }
            }
        }

        return $bodiesByPing;
    }

    /**
     * @param array<string, list<string>> $before
     *
     * @return array<string, list<string>> the bodies added since $before, by ping id
     */
    private function newOutboxBodiesByPing(array $before): array
    {
        $added = [];
        foreach ($this->outboxBodiesByPing() as $pingId => $bodies) {
            $added[$pingId] = \array_slice($bodies, \count($before[$pingId]));
        }

        return $added;
    }

    /**
     * @return array<string, mixed>
     */
    private static function ping(string $organizationId, string $pingId, string $fromId, string $toId, ?string $deliverAfter): array
    {
        return [
            'organization_id' => $organizationId,
            'id' => $pingId,
            'ticket_id' => self::TICKET,
            'from_id' => $fromId,
            'to_id' => $toId,
            'body' => 'Any news on this one?',
            'deliver_after' => $deliverAfter,
            'created_at' => '2027-03-15 10:00:00+00',
        ];
    }

    /**
     * @return array<string, mixed> an open, unread ping bell row
     */
    private static function bellRow(string $organizationId, string $notificationId, string $actorId, string $userId): array
    {
        return [
            'organization_id' => $organizationId,
            'id' => $notificationId,
            'user_id' => $userId,
            'kind' => 'ping',
            'actor_id' => $actorId,
            'ticket_id' => self::TICKET,
            'data' => '{}',
            'created_at' => '2027-03-15 10:00:00+00',
        ];
    }

    private function removeRows(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function markBellGroupDone(): MarkBellGroupDone
    {
        $markBellGroupDone = self::getContainer()->get('test.notification_mark_bell_group_done');
        \assert($markBellGroupDone instanceof MarkBellGroupDone);

        return $markBellGroupDone;
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

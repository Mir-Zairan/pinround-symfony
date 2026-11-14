<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Doctrine;

use App\Notification\Application\Bell\BellGroupKey;
use App\Notification\Application\Bell\BellInbox;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Done and Open on Omar's group from Lena about SHOP-1 in Acme Foods, through the BellInbox a page gets, as
 * pinround_app under row-level security, on 20 March 2027. Next to the group's two open notifications sit the rows the
 * changes must leave alone: done already, held for quiet hours, from Mia, about SHOP-2, to Mia, and of a kind Home shows.
 */
final class DbalBellInboxTest extends KernelTestCase
{
    private const string NOW = '2027-03-20 10:00:00+00:00';
    private const string NOW_IN_UTC = '2027-03-20 10:00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000b3401';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000b3402';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000b3411';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000b3412';
    private const string TICKET_A = '0197a3c0-0000-7000-8000-0000000b3421';
    private const string TICKET_B = '0197a3c0-0000-7000-8000-0000000b3422';
    private const string BAKERY_TICKET = '0197a3c0-0000-7000-8000-0000000b3423';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000b3431';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000b3432';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000b3433';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000b3434';
    private const string READ_PING = '0197a3c0-0000-7000-8000-0000000b3501';
    private const string UNREAD_PING = '0197a3c0-0000-7000-8000-0000000b3502';
    private const string DONE_PING = '0197a3c0-0000-7000-8000-0000000b3503';
    private const string HELD_PING = '0197a3c0-0000-7000-8000-0000000b3504';
    private const string MIAS_QUESTION = '0197a3c0-0000-7000-8000-0000000b3505';
    private const string OTHER_TICKET_PING = '0197a3c0-0000-7000-8000-0000000b3506';
    private const string PING_TO_MIA = '0197a3c0-0000-7000-8000-0000000b3507';
    private const string YOUR_TURN = '0197a3c0-0000-7000-8000-0000000b3508';
    private const string LATE_PING = '0197a3c0-0000-7000-8000-0000000b3509';

    /** The rows as setUp writes them, by id: read_at and done_at in UTC, or null. */
    private const array ROWS_BEFORE = [
        self::READ_PING => ['2027-03-15 13:00:00', null],
        self::UNREAD_PING => [null, null],
        self::DONE_PING => [null, '2027-03-15 12:30:00'],
        self::HELD_PING => [null, null],
        self::MIAS_QUESTION => [null, null],
        self::OTHER_TICKET_PING => [null, null],
        self::PING_TO_MIA => [null, null],
        self::YOUR_TURN => [null, null],
    ];

    protected function setUp(): void
    {
        $this->removeRows();
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET_A, self::SHOP_PROJECT, self::LENA, 1);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET_B, self::SHOP_PROJECT, self::LENA, 2);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BAKERY_TICKET, self::BAKERY_PROJECT, self::LENA);
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_notification', [
            self::notification(self::UNREAD_PING, self::LENA, self::TICKET_A, 'ping', '2027-03-15 11:00:00+00'),
            self::notification(self::READ_PING, self::LENA, self::TICKET_A, 'ping', '2027-03-15 10:00:00+00', ['read_at' => '2027-03-15 13:00:00+00']),
            self::notification(self::DONE_PING, self::LENA, self::TICKET_A, 'ping', '2027-03-15 12:00:00+00', ['done_at' => '2027-03-15 12:30:00+00']),
            self::notification(self::HELD_PING, self::LENA, self::TICKET_A, 'ping', '2027-03-15 14:00:00+00', ['deliver_after' => '2027-03-20 18:00:00+00']),
            self::notification(self::MIAS_QUESTION, self::MIA, self::TICKET_A, 'question', '2027-03-15 15:00:00+00'),
            self::notification(self::OTHER_TICKET_PING, self::LENA, self::TICKET_B, 'ping', '2027-03-16 09:00:00+00'),
            [...self::notification(self::PING_TO_MIA, self::LENA, self::TICKET_A, 'ping', '2027-03-15 16:00:00+00'), 'user_id' => self::MIA],
            self::notification(self::YOUR_TURN, self::LENA, self::TICKET_A, 'your_turn', '2027-03-15 17:00:00+00'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function doneMarksOnlyTheGroupsOpenNotificationsDoneAndTheCountDrops(): void
    {
        self::assertSame(4, $this->openCountOfOmar());

        $marked = $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->bellInbox()->markGroupDone($this->lenasGroupOfOmar(), new \DateTimeImmutable(self::NOW)));

        self::assertSame(2, $marked);
        self::assertSame([
            ...self::ROWS_BEFORE,
            self::READ_PING => ['2027-03-15 13:00:00', self::NOW_IN_UTC],
            self::UNREAD_PING => [null, self::NOW_IN_UTC],
        ], $this->rows());
        self::assertSame(2, $this->openCountOfOmar());
    }

    #[Test]
    public function aSecondDoneFindsNoneOpenAndChangesNothing(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->bellInbox()->markGroupDone($this->lenasGroupOfOmar(), new \DateTimeImmutable(self::NOW)));
        $rowsAfterFirstDone = $this->rows();

        $marked = $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->bellInbox()->markGroupDone($this->lenasGroupOfOmar(), new \DateTimeImmutable('2027-03-20 11:00:00+00:00')));

        self::assertSame(0, $marked);
        self::assertSame($rowsAfterFirstDone, $this->rows());
    }

    #[Test]
    public function doneUnderAnotherOrganizationFindsNoneOpenAndChangesNothing(): void
    {
        $marked = $this->transactions()->committedAs(self::BETA_BAKERY, fn (): int => $this->bellInbox()->markGroupDone($this->lenasGroupOfOmar(), new \DateTimeImmutable(self::NOW)));

        self::assertSame(0, $marked);
        self::assertSame(self::ROWS_BEFORE, $this->rows());
    }

    #[Test]
    public function openMarksTheGroupReadKeepsEarlierReadTimesAndLeavesTheCount(): void
    {
        $ticketKey = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->bellInbox()->markGroupRead($this->lenasGroupOfOmar(), new \DateTimeImmutable(self::NOW)));

        self::assertSame('SHOP-1', $ticketKey);
        self::assertSame([...self::ROWS_BEFORE, self::UNREAD_PING => [self::NOW_IN_UTC, null]], $this->rows());
        self::assertSame(4, $this->openCountOfOmar());
    }

    #[Test]
    public function openOfAGroupWithNoneOpenReturnsNoKeyAndChangesNothing(): void
    {
        $saraGroup = new BellGroupKey(new UserId(self::OMAR), new UserId(self::SARA), new TicketId(self::TICKET_A));

        $ticketKey = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?string => $this->bellInbox()->markGroupRead($saraGroup, new \DateTimeImmutable(self::NOW)));

        self::assertNull($ticketKey);
        self::assertSame(self::ROWS_BEFORE, $this->rows());
    }

    #[Test]
    public function doneOutsideATransactionIsRefusedAndChangesNothing(): void
    {
        $refusal = $this->refusalOutsideATransaction(fn (): int => $this->bellInbox()->markGroupDone($this->lenasGroupOfOmar(), new \DateTimeImmutable(self::NOW)));

        self::assertSame('A bell group is changed in the request transaction; none is open.', $refusal);
        self::assertSame(self::ROWS_BEFORE, $this->rows());
    }

    #[Test]
    public function openOutsideATransactionIsRefusedAndChangesNothing(): void
    {
        $refusal = $this->refusalOutsideATransaction(fn (): ?string => $this->bellInbox()->markGroupRead($this->lenasGroupOfOmar(), new \DateTimeImmutable(self::NOW)));

        self::assertSame('A bell group is changed in the request transaction; none is open.', $refusal);
        self::assertSame(self::ROWS_BEFORE, $this->rows());
    }

    /**
     * A ping written behind the cache's back shows whether a count was read from the cache: the cached count leaves it
     * out, a fresh one has it.
     */
    #[Test]
    public function theCountIsDroppedOnceDoneCommitsAndKeptWhenItRollsBack(): void
    {
        self::assertSame(4, $this->openCountOfOmar());
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_notification', [
            self::notification(self::LATE_PING, self::MIA, self::TICKET_B, 'ping', '2027-03-18 09:00:00+00'),
        ]);
        self::assertSame(4, $this->openCountOfOmar(), 'The count was not read from the cache.');

        $countInsideRolledBackDone = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            self::assertSame(2, $this->bellInbox()->markGroupDone($this->lenasGroupOfOmar(), new \DateTimeImmutable(self::NOW)));

            return $this->bellInbox()->openCountOf(new UserId(self::OMAR), new \DateTimeImmutable(self::NOW));
        });
        self::assertSame(3, $countInsideRolledBackDone, 'Inside the change the count must be read fresh.');
        self::assertSame(4, $this->openCountOfOmar(), 'The count was dropped before the commit.');

        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->bellInbox()->markGroupDone($this->lenasGroupOfOmar(), new \DateTimeImmutable(self::NOW)));

        self::assertSame(3, $this->openCountOfOmar(), 'The count was not dropped after the commit.');
    }

    private function openCountOfOmar(): int
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->bellInbox()->openCountOf(new UserId(self::OMAR), new \DateTimeImmutable(self::NOW)));
    }

    /**
     * @param \Closure(): mixed $change
     */
    private function refusalOutsideATransaction(\Closure $change): string
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            $change();
        } catch (\LogicException $refusal) {
            return $refusal->getMessage();
        } finally {
            $tenantContext->clear();
        }
        self::fail('The change ran outside a transaction.');
    }

    /**
     * @return array<string, array{?string, ?string}> read_at and done_at in UTC by id, of Acme Foods' rows
     */
    private function rows(): array
    {
        $rows = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative(
            "SELECT id, to_char(read_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS read_at,
                 to_char(done_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS done_at
             FROM ntf_notification WHERE organization_id = ? AND id <> ? ORDER BY id",
            [self::ACME_FOODS, self::LATE_PING],
        ));
        $states = [];
        foreach ($rows as $row) {
            self::assertIsString($row['id']);
            $states[$row['id']] = [self::nullableStringOf($row['read_at']), self::nullableStringOf($row['done_at'])];
        }
        $byKnownOrder = [];
        foreach (array_keys(self::ROWS_BEFORE) as $id) {
            self::assertArrayHasKey($id, $states);
            $byKnownOrder[$id] = $states[$id];
        }
        self::assertCount(\count(self::ROWS_BEFORE), $states);

        return $byKnownOrder;
    }

    private static function nullableStringOf(mixed $value): ?string
    {
        self::assertTrue(null === $value || \is_string($value));

        return $value;
    }

    private function lenasGroupOfOmar(): BellGroupKey
    {
        return new BellGroupKey(new UserId(self::OMAR), new UserId(self::LENA), new TicketId(self::TICKET_A));
    }

    /**
     * @param array<string, string> $change the columns that differ from an open, unread one to Omar
     *
     * @return array<string, mixed>
     */
    private static function notification(string $id, string $actorId, string $ticketId, string $kind, string $createdAt, array $change = []): array
    {
        $names = [self::LENA => 'Lena', self::MIA => 'Mia'];
        $keys = [self::TICKET_A => 'SHOP-1', self::TICKET_B => 'SHOP-2'];

        return [
            'organization_id' => self::ACME_FOODS,
            'id' => $id,
            'user_id' => self::OMAR,
            'kind' => $kind,
            'actor_id' => $actorId,
            'ticket_id' => $ticketId,
            'data' => json_encode(['actorName' => $names[$actorId], 'ticketKey' => $keys[$ticketId]], \JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
            ...$change,
        ];
    }

    private function removeRows(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function bellInbox(): BellInbox
    {
        $bellInbox = self::getContainer()->get('test.notification_bell_inbox');
        \assert($bellInbox instanceof BellInbox);

        return $bellInbox;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function notificationRows(): NotificationRows
    {
        return new NotificationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}

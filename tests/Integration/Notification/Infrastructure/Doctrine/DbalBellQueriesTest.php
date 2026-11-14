<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Doctrine;

use App\Notification\Api\BellGroup;
use App\Notification\Api\BellView;
use App\Notification\Api\NotificationQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Omar's bell in Acme Foods, read through NotificationQueries as a page reads it, as pinround_app under row-level
 * security, on 20 March 2027. Next to the five groups it must show sit the rows it must leave out: done, held for
 * quiet hours past now, of a kind Home shows, with no sender, Mia's, and Beta Bakery's. Rows are written out of order.
 */
final class DbalBellQueriesTest extends KernelTestCase
{
    private const string NOW = '2027-03-20 10:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000b3101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000b3102';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000b3111';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000b3112';
    private const string TICKET_A = '0197a3c0-0000-7000-8000-0000000b3121';
    private const string TICKET_B = '0197a3c0-0000-7000-8000-0000000b3122';
    private const string TICKET_C = '0197a3c0-0000-7000-8000-0000000b3123';
    private const string TICKET_D = '0197a3c0-0000-7000-8000-0000000b3124';
    private const string BAKERY_TICKET = '0197a3c0-0000-7000-8000-0000000b3125';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000b3131';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000b3132';
    // Raj's id sorts before Mia's, so ordering tied groups by sender instead of ticket would swap them.
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000b3134';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000b3135';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000b3136';
    private const string FIRST_PING = '0197a3c0-0000-7000-8000-0000000b3301';
    private const string SECOND_PING = '0197a3c0-0000-7000-8000-0000000b3302';
    private const string NEWEST_PING = '0197a3c0-0000-7000-8000-0000000b3303';
    private const string DONE_PING = '0197a3c0-0000-7000-8000-0000000b3304';
    private const string HELD_PING = '0197a3c0-0000-7000-8000-0000000b3305';
    private const string TIED_PING = '0197a3c0-0000-7000-8000-0000000b3306';

    protected function setUp(): void
    {
        $this->removeRows();
        $ticketRows = $this->ticketRows();
        foreach ([self::TICKET_A, self::TICKET_B, self::TICKET_C, self::TICKET_D] as $index => $ticketId) {
            $ticketRows->addOf(self::ACME_FOODS, $ticketId, self::SHOP_PROJECT, self::LENA, $index + 1);
        }
        $ticketRows->addOf(self::BETA_BAKERY, self::BAKERY_TICKET, self::BAKERY_PROJECT, self::LENA);

        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_ping', [
            self::ping(self::FIRST_PING, 'Can you look at the checkout?', '2027-03-15 10:00:00+00'),
            self::ping(self::SECOND_PING, 'Any news on this one?', '2027-03-15 11:00:00+00'),
            self::ping(self::NEWEST_PING, 'Code review is ready when you are.', '2027-03-15 12:00:00.250+00'),
            self::ping(self::DONE_PING, 'Done already.', '2027-03-15 13:00:00+00'),
            self::ping(self::HELD_PING, 'Held for the morning.', '2027-03-15 14:00:00+00'),
            [...self::ping(self::TIED_PING, 'Is the fix on staging?', '2027-03-18 08:00:00+00'), 'ticket_id' => self::TICKET_D, 'from_id' => self::MIA],
        ]);
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_notification', [
            self::notification('0b3203', self::LENA, self::TICKET_A, 'ping', '2027-03-15 12:00:00.250+00', ['pingId' => self::NEWEST_PING]),
            self::notification('0b3201', self::LENA, self::TICKET_A, 'ping', '2027-03-15 10:00:00+00', ['pingId' => self::FIRST_PING], ['read_at' => '2027-03-15 10:30:00+00']),
            self::notification('0b3202', self::LENA, self::TICKET_A, 'ping', '2027-03-15 11:00:00+00', ['pingId' => self::SECOND_PING], ['read_at' => '2027-03-15 11:30:00+00']),
            self::notification('0b3204', self::LENA, self::TICKET_A, 'ping', '2027-03-15 13:00:00+00', ['pingId' => self::DONE_PING], ['done_at' => '2027-03-15 14:00:00+00']),
            self::notification('0b3205', self::LENA, self::TICKET_A, 'ping', '2027-03-15 14:00:00+00', ['pingId' => self::HELD_PING], ['deliver_after' => '2027-03-20 10:00:01+00']),
            self::notification('0b3206', self::LENA, self::TICKET_A, 'your_turn', '2027-03-15 15:00:00+00', []),
            self::notification('0b3207', null, self::TICKET_A, 'ping', '2027-03-15 16:00:00+00', []),
            [...self::notification('0b3208', self::LENA, self::TICKET_A, 'ping', '2027-03-15 17:00:00+00', []), 'user_id' => self::MIA],
            self::notification('0b3231', self::RAJ, self::TICKET_C, 'assigned', '2027-03-17 09:00:00+00', [], ['read_at' => '2027-03-17 10:00:00+00']),
            self::notification('0b3221', self::MIA, self::TICKET_B, 'question', '2027-03-17 09:00:00+00', [], ['deliver_after' => '2027-03-20 10:00:00+00']),
            self::notification('0b3251', self::SARA, self::TICKET_B, 'closed_duplicate', '2027-03-19 09:00:00+00', []),
            self::notification('0b3242', self::MIA, self::TICKET_D, 'ping', '2027-03-18 08:00:00+00', ['pingId' => self::TIED_PING]),
            self::notification('0b3241', self::MIA, self::TICKET_D, 'question', '2027-03-18 08:00:00+00', []),
            self::notification('0b3271', self::RAJ, self::TICKET_B, 'reply', '2027-03-18 12:00:00+00', []),
        ]);
        $this->notificationRows()->addOf(self::BETA_BAKERY, 'ntf_notification', [
            [...self::notification('0b3261', self::LENA, self::BAKERY_TICKET, 'ping', '2027-03-19 18:00:00+00', []), 'organization_id' => self::BETA_BAKERY],
        ]);
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    protected function tearDown(): void
    {
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function theBellGroupsOpenNotificationsBySenderAndTicketLargestThenNewestFirst(): void
    {
        $bell = $this->bellOfOmar();

        self::assertNotSame([], $bell->groups);
        self::assertSame([
            [self::LENA, 'Lena', self::TICKET_A, 'SHOP-1', 3, true, 'ping', 'Code review is ready when you are.', '2027-03-15 12:00:00.250', true],
            [self::MIA, 'Mia', self::TICKET_D, 'SHOP-4', 2, false, 'ping', 'Is the fix on staging?', '2027-03-18 08:00:00.000', true],
            [self::SARA, 'Sara', self::TICKET_B, 'SHOP-2', 1, false, 'closed_duplicate', null, '2027-03-19 09:00:00.000', true],
            [self::RAJ, 'Raj', self::TICKET_B, 'SHOP-2', 1, false, 'reply', null, '2027-03-18 12:00:00.000', true],
            [self::MIA, 'Mia', self::TICKET_B, 'SHOP-2', 1, false, 'question', null, '2027-03-17 09:00:00.000', true],
            [self::RAJ, 'Raj', self::TICKET_C, 'SHOP-3', 1, false, 'assigned', null, '2027-03-17 09:00:00.000', false],
        ], array_map(self::summaryOf(...), $bell->groups));
        self::assertSame(9, $bell->openCount);
    }

    #[Test]
    public function theBellShowsTheFirstFiftyGroupsAndCountsEveryOpenNotification(): void
    {
        $this->notificationRows()->executeOf(
            self::ACME_FOODS,
            "INSERT INTO ntf_notification (organization_id, id, user_id, kind, actor_id, ticket_id, data, created_at)
             SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-8005-' || lpad(to_hex(n), 12, '0') AS uuid), CAST(? AS uuid), 'assigned',
                 CAST('0197a3c0-0000-7000-8004-' || lpad(to_hex(n), 12, '0') AS uuid), CAST(? AS uuid),
                 jsonb_build_object('actorName', 'Omar', 'ticketKey', 'SHOP-3'), TIMESTAMPTZ '2027-03-16 08:00:00+00' + n * INTERVAL '1 minute'
             FROM generate_series(1, 55) AS n",
            [self::ACME_FOODS, self::OMAR, self::TICKET_C],
        );

        $bell = $this->bellOfOmar();

        self::assertCount(50, $bell->groups);
        self::assertSame(64, $bell->openCount);
        self::assertSame([self::LENA, self::MIA], [$bell->groups[0]->senderId->toString(), $bell->groups[1]->senderId->toString()]);
    }

    #[Test]
    public function aNotificationWithoutTheSendersNameInItsSnapshotFailsTheBell(): void
    {
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_notification', [
            [...self::notification('0b3271', self::SARA, self::TICKET_C, 'assigned', '2027-03-19 10:00:00+00', []), 'data' => '{"ticketKey": "SHOP-3"}'],
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('A notification in the bell has no actorName in its data.');

        $this->bellOfOmar();
    }

    #[Test]
    public function theBellNeedsATenant(): void
    {
        self::getContainer()->get(TenantContext::class)->clear();

        $this->expectException(TenantNotSet::class);

        $this->notificationQueries()->bell(new UserId(self::OMAR));
    }

    private function bellOfOmar(): BellView
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): BellView => $this->notificationQueries()->bell(new UserId(self::OMAR)));
    }

    /**
     * @return array{string, string, string, string, int, bool, string, ?string, string, bool}
     */
    private static function summaryOf(BellGroup $group): array
    {
        return [
            $group->senderId->toString(),
            $group->senderName,
            $group->ticketId->toString(),
            $group->ticketKey,
            $group->size,
            $group->isAllPings,
            $group->newestKind,
            $group->newestPingText,
            $group->latestAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
            $group->hasUnread,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ping(string $pingId, string $body, string $createdAt): array
    {
        return [
            'organization_id' => self::ACME_FOODS,
            'id' => $pingId,
            'ticket_id' => self::TICKET_A,
            'from_id' => self::LENA,
            'to_id' => self::OMAR,
            'body' => $body,
            'created_at' => $createdAt,
        ];
    }

    /**
     * A notification to Omar in Acme Foods whose snapshot names the sender and the ticket as the handler writes them.
     *
     * @param string                $idSuffix the last six hex digits of its id
     * @param array<string, string> $ids      what it is about, as pingId
     * @param array<string, string> $change   the columns that differ from an open, unread one
     *
     * @return array<string, mixed>
     */
    private static function notification(string $idSuffix, ?string $actorId, string $ticketId, string $kind, string $createdAt, array $ids, array $change = []): array
    {
        $names = [self::LENA => 'Lena', self::MIA => 'Mia', self::RAJ => 'Raj', self::SARA => 'Sara'];
        $keys = [self::TICKET_A => 'SHOP-1', self::TICKET_B => 'SHOP-2', self::TICKET_C => 'SHOP-3', self::TICKET_D => 'SHOP-4', self::BAKERY_TICKET => 'SHOP-1'];

        return [
            'organization_id' => self::ACME_FOODS,
            'id' => '0197a3c0-0000-7000-8000-000000'.$idSuffix,
            'user_id' => self::OMAR,
            'kind' => $kind,
            'actor_id' => $actorId,
            'ticket_id' => $ticketId,
            'data' => json_encode(['actorName' => $names[$actorId ?? self::LENA] ?? 'Lena', 'ticketKey' => $keys[$ticketId], ...$ids], \JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
            ...$change,
        ];
    }

    private function removeRows(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function notificationQueries(): NotificationQueries
    {
        $notificationQueries = self::getContainer()->get('test.notification_queries');
        \assert($notificationQueries instanceof NotificationQueries);

        return $notificationQueries;
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
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

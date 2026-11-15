<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\Api\NotificationQueries;
use App\Notification\Application\Bell\BellInbox;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\ReadsSentSql;
use App\Tests\Integration\Ticketing\TicketRows;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The queries the bell sends, as the header renders its badge on every page (performance-and-scale.md): Omar has open
 * notifications on three tickets, Mia has none, in Acme Foods on 20 March 2027, as pinround_app under row-level security.
 * Counted inside the transaction, so the tenant set-up the transaction begins with is not counted.
 */
final class BellQueryCountTest extends KernelTestCase
{
    use ReadsSentSql;

    private const string NOW = '2027-03-20 10:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000b3901';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000b3911';
    private const array TICKETS = [
        '0197a3c0-0000-7000-8000-0000000b3921',
        '0197a3c0-0000-7000-8000-0000000b3922',
        '0197a3c0-0000-7000-8000-0000000b3923',
    ];
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000b3931';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000b3932';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000b3933';

    /**
     * The tickets of the open rows, the person's roles, the organization's triager, Ticketing's visibility check, then
     * the grouped bell or the count; three tickets with two rows each, so a query per ticket or group would show.
     */
    private const int QUERIES_OF_A_BELL_WITH_OPEN_ROWS = 5;

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $rows = [];
        foreach (self::TICKETS as $index => $ticketId) {
            $this->ticketRows()->addOf(self::ACME_FOODS, $ticketId, self::SHOP_PROJECT, self::LENA, $index + 1);
            foreach (['10:00', '11:00'] as $timeIndex => $time) {
                $rows[] = [
                    'organization_id' => self::ACME_FOODS,
                    'id' => \sprintf('0197a3c0-0000-7000-8000-0000000b39%d%d', 4 + $index, $timeIndex),
                    'user_id' => self::OMAR,
                    'kind' => 'question',
                    'actor_id' => self::LENA,
                    'ticket_id' => $ticketId,
                    'data' => json_encode(['actorName' => 'Lena', 'ticketKey' => 'SHOP-'.($index + 1)], \JSON_THROW_ON_ERROR),
                    'created_at' => '2027-03-1'.(5 + $index).' '.$time.':00+00',
                ];
            }
        }
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_notification', $rows);
        self::getContainer()->set('clock', new MockClock(self::NOW));
        self::getContainer()->get('cache.bell_count')->clear();
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function theBellOfAPersonWithOpenNotificationsTakesAFixedNumberOfQueries(): void
    {
        [$openCount, $queryCount] = $this->measured(fn (): int => $this->notificationQueries()->bell(new UserId(self::OMAR))->openCount);

        self::assertSame(6, $openCount, 'Omar\'s bell is not the one seeded, so its count proves little.');
        self::assertSame(self::QUERIES_OF_A_BELL_WITH_OPEN_ROWS, $queryCount);
    }

    #[Test]
    public function aColdCountOfAPersonWithOpenNotificationsTakesAFixedNumberOfQueriesAndAWarmOneNone(): void
    {
        [$coldCount, $coldQueryCount] = $this->measured(fn (): int => $this->bellInbox()->openCountOf(new UserId(self::OMAR), new \DateTimeImmutable(self::NOW)));
        [$warmCount, $warmQueryCount] = $this->measured(fn (): int => $this->bellInbox()->openCountOf(new UserId(self::OMAR), new \DateTimeImmutable(self::NOW)));

        self::assertSame([6, self::QUERIES_OF_A_BELL_WITH_OPEN_ROWS], [$coldCount, $coldQueryCount]);
        self::assertSame([6, 0], [$warmCount, $warmQueryCount]);
    }

    #[Test]
    public function theBellOfAPersonWithNoOpenNotificationTakesOneQuery(): void
    {
        [$openCount, $queryCount] = $this->measured(fn (): int => $this->notificationQueries()->bell(new UserId(self::MIA))->openCount);

        self::assertSame([0, 1], [$openCount, $queryCount]);
    }

    #[Test]
    public function aColdCountOfAPersonWithNoOpenNotificationTakesOneQuery(): void
    {
        [$openCount, $queryCount] = $this->measured(fn (): int => $this->bellInbox()->openCountOf(new UserId(self::MIA), new \DateTimeImmutable(self::NOW)));

        self::assertSame([0, 1], [$openCount, $queryCount]);
    }

    /**
     * @param \Closure(): int $read
     *
     * @return array{int, int} what the read returned and the queries it sent
     */
    private function measured(\Closure $read): array
    {
        $debugData = $this->debugData();

        return $this->transactions()->committedAs(self::ACME_FOODS, static function () use ($debugData, $read): array {
            $debugData->reset();
            $value = $read();

            return [$value, self::queryCountOf($debugData)];
        });
    }

    private function notificationQueries(): NotificationQueries
    {
        $notificationQueries = self::getContainer()->get('test.notification_queries');
        \assert($notificationQueries instanceof NotificationQueries);

        return $notificationQueries;
    }

    private function bellInbox(): BellInbox
    {
        $bellInbox = self::getContainer()->get('test.notification_bell_inbox');
        \assert($bellInbox instanceof BellInbox);

        return $bellInbox;
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

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Doctrine;

use App\Notification\Api\BellGroup;
use App\Notification\Api\BellView;
use App\Notification\Api\NotificationQueries;
use App\Notification\Application\Bell\BellInbox;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketQueries;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The cut at TicketQueries::MAX_CHECKED_TICKET_IDS (200) tickets: Omar has open notifications on one ticket more,
 * all of which everyone in Acme Foods may see, on 20 March 2027, as pinround_app under row-level security. SHOP-1 has
 * the lowest id and the oldest rows, three of them, so a cut by id or keeping the oldest would keep it, and a count
 * that kept it would differ. Every other ticket has one row, a minute apart, the highest number the newest.
 */
final class VisibleBellTicketsTest extends KernelTestCase
{
    private const string NOW = '2027-03-20 10:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000b3701';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000b3711';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000b3731';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000b3732';
    private const int TICKET_COUNT = TicketQueries::MAX_CHECKED_TICKET_IDS + 1;
    private const string OLDEST_TICKET = '0197a3c0-0000-7000-800c-000000000001';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $this->ticketRows()->addProjectOf(self::ACME_FOODS, self::SHOP_PROJECT);
        $notificationRows = $this->notificationRows();
        $notificationRows->executeOf(
            self::ACME_FOODS,
            "INSERT INTO tkt_ticket (organization_id, id, project_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
             SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-800c-' || lpad(to_hex(number), 12, '0') AS uuid), CAST(? AS uuid),
                 number, 'SHOP-' || number, 'Checkout button is hidden', '', 'bug', 'major', 2,
                 CAST('0197a3c0-0000-7000-8000-00000000f0d0' AS uuid), CAST(? AS uuid), 'all', '{}', '{}',
                 TIMESTAMPTZ '2027-03-01 08:00:00+00', TIMESTAMPTZ '2027-03-01 08:00:00+00'
             FROM generate_series(1, ?) AS number",
            [self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, self::TICKET_COUNT],
        );
        // Rows 1 to 3 are SHOP-1's, seconds apart before every other; row n from 4 on is SHOP-(n - 2)'s, at n minutes.
        $notificationRows->executeOf(
            self::ACME_FOODS,
            "INSERT INTO ntf_notification (organization_id, id, user_id, kind, actor_id, ticket_id, data, created_at)
             SELECT CAST(? AS uuid), CAST('0197a3c0-0000-7000-800d-' || lpad(to_hex(row_number), 12, '0') AS uuid), CAST(? AS uuid),
                 'question', CAST(? AS uuid), CAST('0197a3c0-0000-7000-800c-' || lpad(to_hex(ticket_number), 12, '0') AS uuid),
                 jsonb_build_object('actorName', 'Lena', 'ticketKey', 'SHOP-' || ticket_number),
                 CASE WHEN row_number <= 3 THEN TIMESTAMPTZ '2027-03-16 08:00:00+00' + row_number * INTERVAL '1 second'
                     ELSE TIMESTAMPTZ '2027-03-16 08:00:00+00' + row_number * INTERVAL '1 minute' END
             FROM generate_series(1, ? + 2) AS row_number,
                 LATERAL (SELECT GREATEST(row_number - 2, 1) AS ticket_number) AS ticket",
            [self::ACME_FOODS, self::OMAR, self::LENA, self::TICKET_COUNT],
        );
        self::getContainer()->set('clock', new MockClock(self::NOW));
        self::getContainer()->get('cache.bell_count')->clear();
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function theBellLeavesOutTheTicketWithTheOldestRowsPastTheCut(): void
    {
        $bell = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): BellView => $this->notificationQueries()->bell(new UserId(self::OMAR)));

        self::assertSame(TicketQueries::MAX_CHECKED_TICKET_IDS, $bell->openCount);
        self::assertNotSame([], $bell->groups);
        self::assertNotContains(self::OLDEST_TICKET, array_map(static fn (BellGroup $group): string => $group->ticketId->toString(), $bell->groups));
        self::assertSame(
            array_map(static fn (int $number): string => 'SHOP-'.$number, range(self::TICKET_COUNT, self::TICKET_COUNT - 49)),
            array_map(static fn (BellGroup $group): string => $group->ticketKey, $bell->groups),
        );
    }

    #[Test]
    public function theCountLeavesOutTheTicketWithTheOldestRowsPastTheCut(): void
    {
        $openCount = $this->transactions()->committedAs(self::ACME_FOODS, fn (): int => $this->bellInbox()->openCountOf(new UserId(self::OMAR), new \DateTimeImmutable(self::NOW)));

        self::assertSame(TicketQueries::MAX_CHECKED_TICKET_IDS, $openCount);
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

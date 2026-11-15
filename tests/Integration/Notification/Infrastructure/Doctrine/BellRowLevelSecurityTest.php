<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Doctrine;

use App\Notification\Api\BellView;
use App\Notification\Infrastructure\Cache\BellCountCache;
use App\Notification\Infrastructure\Doctrine\DbalBellInbox;
use App\Notification\Infrastructure\Doctrine\DbalBellQueries;
use App\Notification\Infrastructure\Doctrine\VisibleBellTickets;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The bell's reads on 20 March 2027 when the SQL names Acme Foods while the transaction's tenant is Beta Bakery: what
 * still hides Omar's three open Acme Foods notifications is the ntf_notification row-level security policy alone. The
 * Ticketing check is a double that keeps every ticket, so it hides nothing, and each case first reads the same rows
 * under Acme Foods with the same objects, so a bell that finds nothing for another reason fails too.
 */
final class BellRowLevelSecurityTest extends KernelTestCase
{
    private const string NOW = '2027-03-20 10:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000b5101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000b5102';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000b5111';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000b5112';
    private const string TICKET_A = '0197a3c0-0000-7000-8000-0000000b5121';
    private const string TICKET_B = '0197a3c0-0000-7000-8000-0000000b5122';
    private const string BAKERY_TICKET = '0197a3c0-0000-7000-8000-0000000b5123';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000b5131';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000b5132';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000b5133';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET_A, self::SHOP_PROJECT, self::LENA, 1);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET_B, self::SHOP_PROJECT, self::LENA, 2);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BAKERY_TICKET, self::BAKERY_PROJECT, self::LENA);
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_notification', [
            self::notification(self::ACME_FOODS, '0b5203', self::MIA, self::TICKET_B, '2027-03-18 09:00:00+00'),
            self::notification(self::ACME_FOODS, '0b5201', self::LENA, self::TICKET_A, '2027-03-16 09:00:00+00'),
            self::notification(self::ACME_FOODS, '0b5202', self::LENA, self::TICKET_A, '2027-03-17 09:00:00+00'),
        ]);
        $this->notificationRows()->addOf(self::BETA_BAKERY, 'ntf_notification', [
            self::notification(self::BETA_BAKERY, '0b5204', self::LENA, self::BAKERY_TICKET, '2027-03-19 09:00:00+00'),
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsBell(): void
    {
        $readBell = fn (Connection $connection): BellView => $this->bellQueriesOn($connection)->bell(new UserId(self::OMAR));

        $acmeBell = $this->ownTransactions()->rolledBackAs(self::ACME_FOODS, $readBell);
        self::assertSame(3, $acmeBell->openCount);
        self::assertCount(2, $acmeBell->groups);

        $bellUnderAcmeContext = $this->ownTransactions()->rolledBackUnderAnotherContext(self::BETA_BAKERY, self::ACME_FOODS, $readBell);

        self::assertSame([0, []], [$bellUnderAcmeContext->openCount, $bellUnderAcmeContext->groups]);
    }

    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsOpenCount(): void
    {
        $countOpen = fn (Connection $connection): int => $this->bellInboxOn($connection)->openCountOf(new UserId(self::OMAR), new \DateTimeImmutable(self::NOW));

        self::assertSame(3, $this->ownTransactions()->rolledBackAs(self::ACME_FOODS, $countOpen));

        self::assertSame(0, $this->ownTransactions()->rolledBackUnderAnotherContext(self::BETA_BAKERY, self::ACME_FOODS, $countOpen));
    }

    private function bellQueriesOn(Connection $connection): DbalBellQueries
    {
        return new DbalBellQueries($connection, $this->tenantContext(), new MockClock(self::NOW), $this->visibleTicketsOn($connection));
    }

    /**
     * The own connection lacks the middleware that tells the count cache a transaction began, so the cache keeps
     * nothing and the second read cannot get the first one's count.
     */
    private function bellInboxOn(Connection $connection): DbalBellInbox
    {
        return new DbalBellInbox($connection, $this->tenantContext(), self::getContainer()->get(BellCountCache::class), $this->visibleTicketsOn($connection));
    }

    /**
     * Ticketing's check is a double that keeps every ticket it is asked about, so only row-level security can hide one.
     */
    private function visibleTicketsOn(Connection $connection): VisibleBellTickets
    {
        $ticketVisibilities = self::createStub(TicketVisibilities::class);
        $ticketVisibilities->method('of')->willReturnCallback(
            static fn (OrganizationId $organizationId, UserId $viewerId): TicketVisibility => TicketVisibility::for($organizationId, $viewerId, [], true, null),
        );
        $ticketQueries = self::createStub(TicketQueries::class);
        $ticketQueries->method('visibleTicketIdsOf')->willReturnCallback(
            /** @param list<TicketId> $ticketIds */
            static fn (TicketVisibility $viewer, array $ticketIds): array => array_values($ticketIds),
        );

        return new VisibleBellTickets($connection, $this->tenantContext(), $ticketVisibilities, $ticketQueries);
    }

    /**
     * An open, unread notification to Omar whose snapshot names the sender and the ticket as the handler writes them.
     *
     * @param string $idSuffix the last six hex digits of its id
     *
     * @return array<string, mixed>
     */
    private static function notification(string $organizationId, string $idSuffix, string $actorId, string $ticketId, string $createdAt): array
    {
        return [
            'organization_id' => $organizationId,
            'id' => '0197a3c0-0000-7000-8000-000000'.$idSuffix,
            'user_id' => self::OMAR,
            'kind' => 'question',
            'actor_id' => $actorId,
            'ticket_id' => $ticketId,
            'data' => json_encode(['actorName' => self::LENA === $actorId ? 'Lena' : 'Mia', 'ticketKey' => 'SHOP-1'], \JSON_THROW_ON_ERROR),
            'created_at' => $createdAt,
        ];
    }

    private function removeRows(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function ownTransactions(): OwnConnectionTransactions
    {
        return new OwnConnectionTransactions(self::getContainer()->get('doctrine.dbal.default_connection'), $this->tenantContext());
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function notificationRows(): NotificationRows
    {
        return new NotificationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}

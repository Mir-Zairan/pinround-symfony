<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Doctrine;

use App\Notification\Application\Ping\RecentPings;
use App\Notification\Infrastructure\Doctrine\DbalRecentPingQueries;
use App\Notification\UI\PingedState;
use App\Organization\Api\PingSettingsQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Lena's recent pings about one ticket of Acme Foods, whose ping limit is 8 hours, read as pinround_app under row-level
 * security at 10:00 on 15 March 2027, so the window opens at 02:00. Next to the pings it must count sit the ones it
 * must leave out: before the window, at its very start, Mia's, about another ticket and Beta Bakery's. Rows are written
 * out of order.
 */
final class DbalRecentPingQueriesTest extends KernelTestCase
{
    private const string NOW = '2027-03-15 10:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c6101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000c6102';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000c6111';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000c6112';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000c6121';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000c6122';
    private const string BAKERY_TICKET = '0197a3c0-0000-7000-8000-0000000c6123';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c6131';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c6132';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000c6133';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000c6134';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000c6135';

    protected function setUp(): void
    {
        $this->removeRows();
        $ticketRows = $this->ticketRows();
        $ticketRows->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::OMAR, 1);
        $ticketRows->addOf(self::ACME_FOODS, self::OTHER_TICKET, self::SHOP_PROJECT, self::OMAR, 2);
        $ticketRows->addOf(self::BETA_BAKERY, self::BAKERY_TICKET, self::BAKERY_PROJECT, self::OMAR);
        $this->notificationRows()->addOf(self::ACME_FOODS, 'org_settings', [
            ['organization_id' => self::ACME_FOODS, 'data' => '{"ping_limit_hours": 8}', 'created_at' => '2027-03-01 09:00:00+00'],
        ]);
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_ping', [
            self::ping('c6201', self::LENA, self::OMAR, self::TICKET, '2027-03-15 03:00:00+00'),
            // Cleared since, and still the newest: clearing a ping does not give the sender their slot back.
            [...self::ping('c6202', self::LENA, self::OMAR, self::TICKET, '2027-03-15 06:30:00+00'), 'cleared_at' => '2027-03-15 07:00:00+00', 'cleared_reason' => 'done'],
            self::ping('c6203', self::LENA, self::OMAR, self::TICKET, '2027-03-15 04:15:00+00'),
            self::ping('c6204', self::LENA, self::SARA, self::TICKET, '2027-03-15 02:00:01+00'),
            self::ping('c6205', self::LENA, self::RAJ, self::TICKET, '2027-03-15 02:00:00+00'),
            self::ping('c6206', self::LENA, self::MIA, self::TICKET, '2027-03-14 23:00:00+00'),
            self::ping('c6207', self::MIA, self::RAJ, self::TICKET, '2027-03-15 09:00:00+00'),
            self::ping('c6208', self::LENA, self::MIA, self::OTHER_TICKET, '2027-03-15 09:00:00+00'),
        ]);
        $this->notificationRows()->addOf(self::BETA_BAKERY, 'ntf_ping', [
            [...self::ping('c6209', self::LENA, self::RAJ, self::BAKERY_TICKET, '2027-03-15 09:30:00+00'), 'organization_id' => self::BETA_BAKERY],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function eachPersonPingedWithinTheLimitMayBePingedAgainTheLimitAfterTheNewestPing(): void
    {
        $recentPings = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): RecentPings => $this->recentPingsOfLena(self::ACME_FOODS));

        self::assertSame(8, $recentPings->limitHours);
        self::assertSame([
            self::OMAR => '2027-03-15 14:30:00',
            self::SARA => '2027-03-15 10:00:01',
            self::MIA => null,
            self::RAJ => null,
            self::LENA => null,
        ], self::againAtByPerson($recentPings));
    }

    #[Test]
    public function underAnotherTenantNobodyWasPingedAndTheLimitIsTheDefault(): void
    {
        $recentPings = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): RecentPings => $this->recentPingsOfLena(self::ACME_FOODS));

        self::assertSame(4, $recentPings->limitHours);
        self::assertSame([self::OMAR => null, self::SARA => null, self::MIA => null, self::RAJ => null, self::LENA => null], self::againAtByPerson($recentPings));
    }

    #[Test]
    public function pingedStateIsWiredAndReadsInATransactionOfItsOwnAtTheAppClocksTime(): void
    {
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $pingedState = self::getContainer()->get(PingedState::class);
        self::assertFalse($this->connection()->isTransactionActive());

        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            $recentPings = $pingedState->recentPingsOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new TicketId(self::TICKET));
        } finally {
            $tenantContext->clear();
        }

        self::assertSame(8, $recentPings->limitHours);
        self::assertSame([
            self::OMAR => '2027-03-15 14:30:00',
            self::SARA => '2027-03-15 10:00:01',
            self::MIA => null,
            self::RAJ => null,
            self::LENA => null,
        ], self::againAtByPerson($recentPings));
        self::assertFalse($this->connection()->isTransactionActive());
    }

    private function recentPingsOfLena(string $organizationId): RecentPings
    {
        $pingSettingsQueries = self::getContainer()->get(PingSettingsQueries::class);

        return new DbalRecentPingQueries($this->connection(), $pingSettingsQueries)
            ->recentPingsOf(new OrganizationId($organizationId), new UserId(self::LENA), new TicketId(self::TICKET), new \DateTimeImmutable(self::NOW));
    }

    /**
     * @return array<string, ?string> when Lena may ping each person again, in UTC, null for anytime
     */
    private static function againAtByPerson(RecentPings $recentPings): array
    {
        $againAtByPerson = [];
        foreach ([self::OMAR, self::SARA, self::MIA, self::RAJ, self::LENA] as $personId) {
            $againAt = $recentPings->againAtOf(new UserId($personId));
            $againAtByPerson[$personId] = $againAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        return $againAtByPerson;
    }

    /**
     * @param string $idSuffix the last five hex digits of its id
     *
     * @return array<string, mixed>
     */
    private static function ping(string $idSuffix, string $fromId, string $toId, string $ticketId, string $createdAt): array
    {
        return [
            'organization_id' => self::ACME_FOODS,
            'id' => '0197a3c0-0000-7000-8000-0000000'.$idSuffix,
            'ticket_id' => $ticketId,
            'from_id' => $fromId,
            'to_id' => $toId,
            'body' => 'Can you look at this one?',
            'created_at' => $createdAt,
        ];
    }

    private function removeRows(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->notificationRows()->executeOf(self::ACME_FOODS, 'DELETE FROM org_settings WHERE organization_id = ?', [self::ACME_FOODS]);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
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

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Doctrine;

use App\Notification\Api\NotificationQueries;
use App\Notification\Infrastructure\Doctrine\DbalBellQueries;
use App\Notification\Infrastructure\Doctrine\DbalNotificationQueries;
use App\Notification\Infrastructure\Doctrine\VisibleBellTickets;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Notification\NotificationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Whether a person muted a ticket, read through NotificationQueries as pinround_app under row-level security in Acme
 * Foods on 20 March 2027. Omar muted SHOP-1 only; Mia muted SHOP-2; Omar's mute in Beta Bakery must not reach Acme Foods.
 */
final class DbalNotificationQueriesTest extends KernelTestCase
{
    private const string NOW = '2027-03-20 10:00:00+00:00';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000b4101';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000b4102';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000b4111';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000b4112';
    private const string MUTED_TICKET = '0197a3c0-0000-7000-8000-0000000b4121';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000b4122';
    private const string BAKERY_TICKET = '0197a3c0-0000-7000-8000-0000000b4123';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000b4131';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000b4132';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000b4133';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->ticketRows()->addOf(self::ACME_FOODS, self::MUTED_TICKET, self::SHOP_PROJECT, self::LENA, 1);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::OTHER_TICKET, self::SHOP_PROJECT, self::LENA, 2);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BAKERY_TICKET, self::BAKERY_PROJECT, self::LENA);
        $this->notificationRows()->addOf(self::ACME_FOODS, 'ntf_mute', [
            self::mute(self::ACME_FOODS, self::MIA, self::OTHER_TICKET),
            self::mute(self::ACME_FOODS, self::OMAR, self::MUTED_TICKET),
        ]);
        $this->notificationRows()->addOf(self::BETA_BAKERY, 'ntf_mute', [
            self::mute(self::BETA_BAKERY, self::OMAR, self::BAKERY_TICKET),
        ]);
        self::getContainer()->set('clock', new MockClock(self::NOW));
    }

    protected function tearDown(): void
    {
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function aPersonWhoMutedTheTicketIsMuted(): void
    {
        self::assertTrue($this->isMutedInAcmeFoods(self::OMAR, self::MUTED_TICKET));
    }

    #[Test]
    public function aMuteCoversOnlyItsOwnTicket(): void
    {
        self::assertFalse($this->isMutedInAcmeFoods(self::OMAR, self::OTHER_TICKET));
    }

    #[Test]
    public function aMuteCoversOnlyThePersonWhoMuted(): void
    {
        self::assertFalse($this->isMutedInAcmeFoods(self::LENA, self::MUTED_TICKET));
    }

    #[Test]
    public function aMuteInAnotherOrganizationDoesNotCount(): void
    {
        self::assertFalse($this->isMutedInAcmeFoods(self::OMAR, self::BAKERY_TICKET));
    }

    /**
     * The SQL names Acme Foods while the transaction's tenant is Beta Bakery, so only row-level security hides Omar's
     * Acme Foods mute; the same objects under Acme Foods find it.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsMute(): void
    {
        $isOmarMuted = fn (Connection $connection): bool => $this->notificationQueriesOn($connection)->isMuted(new UserId(self::OMAR), new TicketId(self::MUTED_TICKET));
        self::assertTrue($this->ownTransactions()->rolledBackAs(self::ACME_FOODS, $isOmarMuted));

        self::assertFalse($this->ownTransactions()->rolledBackUnderAnotherContext(self::BETA_BAKERY, self::ACME_FOODS, $isOmarMuted));
    }

    private function notificationQueriesOn(Connection $connection): DbalNotificationQueries
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $visibleTickets = new VisibleBellTickets($connection, $tenantContext, self::createStub(TicketVisibilities::class), self::createStub(TicketQueries::class));

        return new DbalNotificationQueries($connection, $tenantContext, new DbalBellQueries($connection, $tenantContext, new MockClock(self::NOW), $visibleTickets));
    }

    private function ownTransactions(): OwnConnectionTransactions
    {
        return new OwnConnectionTransactions(self::getContainer()->get('doctrine.dbal.default_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function isMutedInAcmeFoods(string $userId, string $ticketId): bool
    {
        return $this->transactions()->rolledBackAs(
            self::ACME_FOODS,
            fn (): bool => $this->notificationQueries()->isMuted(new UserId($userId), new TicketId($ticketId)),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function mute(string $organizationId, string $userId, string $ticketId): array
    {
        return ['organization_id' => $organizationId, 'user_id' => $userId, 'ticket_id' => $ticketId, 'created_at' => '2027-03-18 09:00:00+00'];
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

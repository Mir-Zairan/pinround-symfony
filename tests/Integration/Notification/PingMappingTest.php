<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\Domain\Ping;
use App\Notification\Domain\PingClearedReason;
use App\Notification\Domain\PingId;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ping saved through Doctrine and found again in a cleared EntityManager, so every field comes back from ntf_ping, not
 * from the identity map. Clearing has no domain method yet, so it is written as SQL.
 */
final class PingMappingTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000004221';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-000000004222';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000004223';
    private const string LENA = '0197a3c0-0000-7000-8000-000000004224';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000004225';
    private const string HELD_PING = '0197a3c0-0000-7000-8000-000000004226';
    private const string SHOWN_PING = '0197a3c0-0000-7000-8000-000000004227';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA);
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function aPingHeldForQuietHoursComesBackWithEveryField(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->entityManager()->persist(Ping::send(new PingId(self::HELD_PING), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), 'Can you look at this today? ✓', new \DateTimeImmutable('2027-03-16 08:00:00+00:00'), new \DateTimeImmutable('2027-03-15 21:30:00+00:00')));
            $this->entityManager()->flush();
        });

        $ping = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?Ping => $this->findPing(self::HELD_PING));

        self::assertNotNull($ping);
        self::assertSame(
            [self::HELD_PING, self::ACME_FOODS, self::TICKET, self::LENA, self::OMAR, 'Can you look at this today? ✓', null, null],
            [$ping->id()->toString(), $ping->organizationId()->toString(), $ping->ticketId()->toString(), $ping->fromId()->toString(), $ping->toId()->toString(), $ping->body(), $ping->clearedAt(), $ping->clearedReason()],
        );
        self::assertEquals(new \DateTimeImmutable('2027-03-16 08:00:00+00:00'), $ping->deliverAfter());
        self::assertEquals(new \DateTimeImmutable('2027-03-15 21:30:00+00:00'), $ping->createdAt());
    }

    #[Test]
    public function aClearedPingComesBackWithWhenAndWhy(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->entityManager()->persist($this->shownPing());
            $this->entityManager()->flush();
        });
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            "UPDATE ntf_ping SET cleared_at = '2027-03-15 10:45:00+00', cleared_reason = 'acted' WHERE id = ?",
            [self::SHOWN_PING],
        ));

        $ping = $this->transactions()->committedAs(self::ACME_FOODS, fn (): ?Ping => $this->findPing(self::SHOWN_PING));

        self::assertNotNull($ping);
        self::assertSame([null, PingClearedReason::Acted], [$ping->deliverAfter(), $ping->clearedReason()]);
        self::assertEquals(new \DateTimeImmutable('2027-03-15 10:45:00+00:00'), $ping->clearedAt());
    }

    #[Test]
    public function savingAPingTwiceKeepsOneRow(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $ping = $this->shownPing();
            $this->entityManager()->persist($ping);
            $this->entityManager()->flush();
            $this->entityManager()->persist($ping);
            $this->entityManager()->flush();
        });

        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->entityManager()->persist($this->findPing(self::SHOWN_PING) ?? throw new \LogicException('The ping was not saved.'));
            $this->entityManager()->flush();
        });

        self::assertSame(1, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM ntf_ping WHERE ticket_id = ?', [self::TICKET])));
    }

    private function shownPing(): Ping
    {
        return Ping::send(new PingId(self::SHOWN_PING), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), 'Still on it?', null, new \DateTimeImmutable('2027-03-15 10:00:00+00:00'));
    }

    private function findPing(string $pingId): ?Ping
    {
        return $this->entityManager()->find(Ping::class, ['organizationId' => self::ACME_FOODS, 'id' => $pingId]);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}

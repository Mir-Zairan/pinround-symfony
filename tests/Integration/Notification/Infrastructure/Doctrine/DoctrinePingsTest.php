<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\Doctrine;

use App\Notification\Domain\Ping;
use App\Notification\Infrastructure\Doctrine\DoctrinePings;
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

final class DoctrinePingsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000005201';
    private const string OTHER_ORGANIZATION = '0197a3c0-0000-7000-8000-000000005202';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-000000005203';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000005204';
    private const string LENA = '0197a3c0-0000-7000-8000-000000005205';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000005206';

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
    public function anAddedPingIsOneRowOfItsOrganizationOnly(): void
    {
        $pingId = $this->transactions()->committedAs(self::ACME_FOODS, function (): string {
            $ping = $this->ping();
            $this->pings()->add($ping);

            return $ping->id()->toString();
        });

        self::assertSame(
            [['id' => $pingId, 'organization_id' => self::ACME_FOODS, 'body' => 'Any news?']],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative('SELECT id, organization_id, body FROM ntf_ping WHERE ticket_id = ?', [self::TICKET])),
        );
        self::assertSame(0, $this->transactions()->rolledBackAs(self::OTHER_ORGANIZATION, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM ntf_ping WHERE ticket_id = ?', [self::TICKET])));
    }

    #[Test]
    public function addingAPingOutsideATransactionIsRefusedAndStoresNothing(): void
    {
        try {
            $this->pings()->add($this->ping());
            self::fail('A ping was added outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Adding a ping needs an open transaction.', $refusal->getMessage());
        }

        self::assertSame(0, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM ntf_ping WHERE ticket_id = ?', [self::TICKET])));
    }

    private function ping(): Ping
    {
        return Ping::send($this->pings()->nextIdentity(), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), ' Any news? ', null, new \DateTimeImmutable('2027-03-15 10:00:00+00:00'));
    }

    private function pings(): DoctrinePings
    {
        return new DoctrinePings($this->entityManager());
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

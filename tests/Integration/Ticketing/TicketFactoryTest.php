<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\Tests\Factory\Ticketing\TicketFactory;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketCommands;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TicketFactoryTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e701';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000e711';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000e721';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e759';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e752';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
        ]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function oneLineMakesAShopTicketWithItsEventRowAndTurnRow(): void
    {
        $ticketId = $this->factory()->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, ['assignee' => self::OMAR]);

        $rows = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->connection()->fetchOne('SELECT key FROM tkt_ticket WHERE id = ?', [$ticketId->toString()]),
            $this->connection()->fetchOne("SELECT count(*) FROM tkt_ticket_event WHERE ticket_id = ? AND type = 'created'", [$ticketId->toString()]),
            $this->connection()->fetchOne('SELECT count(*) FROM ins_ticket_turn WHERE ticket_id = ?', [$ticketId->toString()]),
        ]);

        self::assertIsString($rows[0]);
        self::assertMatchesRegularExpression('/^SHOP-\d+$/', $rows[0]);
        self::assertSame([1, 1], [$rows[1], $rows[2]]);
    }

    #[Test]
    public function twoTicketsGetTheNextNumbers(): void
    {
        $first = $this->factory()->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA);
        $second = $this->factory()->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::LENA, ['title' => 'Price rounds wrong in basket']);

        $numberAfterFirst = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->connection()->fetchOne(
            'SELECT b.number - a.number FROM tkt_ticket a, tkt_ticket b WHERE a.id = ? AND b.id = ?',
            [$first->toString(), $second->toString()],
        ));

        self::assertSame(1, $numberAfterFirst);
    }

    private function factory(): TicketFactory
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);

        return new TicketFactory($ticketCommands, $this->transactions(), $this->connection());
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR], self::ACME_FOODS);
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }
}

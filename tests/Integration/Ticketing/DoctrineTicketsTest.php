<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Domain\InvalidTicketKey;
use App\Ticketing\Infrastructure\Doctrine\DoctrineTickets;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineTickets::ofKey as pinround_app under row-level security, with Acme Foods and Beta Bakery each having a ticket
 * with the same key in a project with the same id.
 */
final class DoctrineTicketsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e701';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000e702';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000e711';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000e721';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e751';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e752';

    protected function setUp(): void
    {
        $this->removeRows();
        $this->shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
        ]);
        $this->shopTeamRows()->add(self::BETA_BAKERY, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, []);
        $this->shopTeamRows()->addMember(self::BETA_BAKERY, self::SHOP_TEAM, self::LENA, 'lead', 'developer');
        $this->shopTeamRows()->addMember(self::BETA_BAKERY, self::SHOP_TEAM, self::OMAR, 'member', 'developer');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function itFindsTheTicketOfTheOrganizationWithTheKey(): void
    {
        $acmeTicketId = $this->createdTicketOf(self::ACME_FOODS);
        $betaTicketId = $this->createdTicketOf(self::BETA_BAKERY);
        $key = $this->keyOf(self::ACME_FOODS, $acmeTicketId);
        self::assertSame($key, $this->keyOf(self::BETA_BAKERY, $betaTicketId));

        $found = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => [
            $this->tickets()->ofKey(new OrganizationId(self::BETA_BAKERY), $key)?->id()->toString(),
            $this->tickets()->ofKey(new OrganizationId(self::BETA_BAKERY), 'SHOP-999')?->id()->toString(),
        ]);

        self::assertSame([$betaTicketId, null], $found);
    }

    /**
     * Beta Bakery's tenant hides Acme Foods' ticket even when the SQL names Acme Foods, and under Acme Foods' tenant
     * the SQL's organization alone hides it from a lookup for Beta Bakery, which has no ticket.
     */
    #[Test]
    public function anotherOrganizationsKeyIsNotFound(): void
    {
        $acmeTicketId = $this->createdTicketOf(self::ACME_FOODS);
        $key = $this->keyOf(self::ACME_FOODS, $acmeTicketId);

        $found = [
            $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?string => $this->tickets()->ofKey(new OrganizationId(self::ACME_FOODS), $key)?->id()->toString()),
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?string => $this->tickets()->ofKey(new OrganizationId(self::BETA_BAKERY), $key)?->id()->toString()),
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?string => $this->tickets()->ofKey(new OrganizationId(self::ACME_FOODS), $key)?->id()->toString()),
        ];

        self::assertSame([null, null, $acmeTicketId], $found);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedKeys(): iterable
    {
        yield 'lower case' => ['shop-1'];
        yield 'a trailing newline' => ["SHOP-1\n"];
        yield 'a leading zero' => ['SHOP-01'];
        yield 'no number' => ['SHOP-'];
        yield 'a uuid' => ['0197a3c0-0000-7000-8000-00000000e721'];
    }

    #[Test]
    #[DataProvider('malformedKeys')]
    public function aMalformedKeyIsRefused(string $key): void
    {
        $this->expectException(InvalidTicketKey::class);
        $this->expectExceptionMessage('A ticket key is a project key, a hyphen and a number, such as SHOP-12.');
        $this->tickets()->ofKey(new OrganizationId(self::ACME_FOODS), $key);
    }

    private function createdTicketOf(string $organizationId): string
    {
        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        self::assertInstanceOf(TicketCommands::class, $ticketCommands);

        return new ShopTickets($ticketCommands, $this->transactions(), $this->entityManager()->getConnection())
            ->createdFor($organizationId, self::SHOP_PROJECT, self::LENA, self::OMAR)
            ->toString();
    }

    private function keyOf(string $organizationId, string $ticketId): string
    {
        $key = $this->transactions()->rolledBackAs($organizationId, fn (): mixed => $this->entityManager()->getConnection()->fetchOne('SELECT key FROM tkt_ticket WHERE id = ?', [$ticketId]));
        self::assertIsString($key);

        return $key;
    }

    private function tickets(): DoctrineTickets
    {
        return new DoctrineTickets($this->entityManager());
    }

    private function removeRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::LENA, self::OMAR], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
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

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketKeyQueries;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalTicketKeyQueries as pinround_app on committed tickets of two organizations.
 */
final class DbalTicketKeyQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000072a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000072a2';
    private const string ACME_PROJECT = '0197a3c0-0000-7000-8000-0000000072c1';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-0000000072c2';
    private const string ACME_TICKET = '0197a3c0-0000-7000-8000-0000000072d1';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-0000000072d2';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000072b1';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::ACME_TICKET, self::ACME_PROJECT, self::LENA, 412);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, self::LENA, 7);
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function theTicketOfTheTenantGivesItsKey(): void
    {
        self::assertSame('SHOP-412', $this->keyAs(self::ACME_FOODS, self::ACME_TICKET));
        self::assertSame('SHOP-7', $this->keyAs(self::BETA_BAKERY, self::BETA_TICKET));
    }

    #[Test]
    public function aTicketOfAnotherOrganizationOrNoneHasNoKey(): void
    {
        self::assertNull($this->keyAs(self::ACME_FOODS, self::BETA_TICKET));
        self::assertNull($this->keyAs(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000072d9'));
    }

    #[Test]
    public function noTenantIsRefused(): void
    {
        $this->expectException(TenantNotSet::class);

        $this->queries()->keyOf(new TicketId(self::ACME_TICKET));
    }

    private function keyAs(string $organizationId, string $ticketId): ?string
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): ?string => $this->queries()->keyOf(new TicketId($ticketId)));
    }

    private function queries(): DbalTicketKeyQueries
    {
        return new DbalTicketKeyQueries(self::getContainer()->get('doctrine.dbal.default_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Infrastructure\Doctrine\TicketOpenTicketCounts;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * TicketOpenTicketCounts on tkt_ticket as pinround_app under row-level security. Beta Bakery has more open tickets in a
 * project of the same id, so a count that reads past the tenant differs from the right one.
 */
final class TicketOpenTicketCountsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000c801';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000c802';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000c811';
    private const string APP_PROJECT = '0197a3c0-0000-7000-8000-00000000c812';
    private const string FIRST_TICKET = '0197a3c0-0000-7000-8000-00000000c821';
    private const string SECOND_TICKET = '0197a3c0-0000-7000-8000-00000000c822';
    private const string CLOSED_TICKET = '0197a3c0-0000-7000-8000-00000000c823';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000c831';

    protected function setUp(): void
    {
        $rows = $this->ticketRows();
        $rows->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            foreach ([self::FIRST_TICKET, self::SECOND_TICKET, self::CLOSED_TICKET] as $index => $ticketId) {
                $rows->addOf($organizationId, $ticketId, self::SHOP_PROJECT, self::MIA, $index + 1);
            }
        }
        $rows->closeOf(self::ACME_FOODS, self::CLOSED_TICKET);
        $rows->addKeyedProjectOf(self::ACME_FOODS, self::APP_PROJECT, null, 'APP', 1);
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        self::getContainer()->get(TenantContext::class)->reset();
        parent::tearDown();
    }

    #[Test]
    public function itCountsOnlyTheOpenTicketsOfTheCurrentOrganizationPerProject(): void
    {
        $counts = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->openTicketCounts()->openTicketsIn(
            new ProjectId(self::SHOP_PROJECT),
            new ProjectId(self::APP_PROJECT),
        ));

        self::assertSame([self::SHOP_PROJECT => 2, self::APP_PROJECT => 0], $counts);
    }

    #[Test]
    public function aProjectWithoutTicketsCountsZeroWhileAnotherProjectHasSome(): void
    {
        $counts = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->openTicketCounts()->openTicketsIn(new ProjectId(self::APP_PROJECT)));

        self::assertSame([self::APP_PROJECT => 0], $counts);
    }

    #[Test]
    public function noProjectsGiveNoCounts(): void
    {
        self::assertSame([], $this->openTicketCounts()->openTicketsIn());
    }

    private function openTicketCounts(): TicketOpenTicketCounts
    {
        return new TicketOpenTicketCounts(self::getContainer()->get('doctrine.dbal.default_connection'));
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

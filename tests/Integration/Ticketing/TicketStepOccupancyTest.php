<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Infrastructure\Workflow\TicketStepOccupancy;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * TicketStepOccupancy on tkt_ticket as pinround_app under row-level security. TicketRows puts every ticket in the same
 * To do step; Beta Bakery has more open tickets in a step of the same id, so a count that reads past the tenant
 * differs from the right one.
 */
final class TicketStepOccupancyTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000c901';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000c902';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000c911';
    private const string FIRST_TICKET = '0197a3c0-0000-7000-8000-00000000c921';
    private const string SECOND_TICKET = '0197a3c0-0000-7000-8000-00000000c922';
    private const string CLOSED_TICKET = '0197a3c0-0000-7000-8000-00000000c923';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000c931';
    // The step TicketRows::addOf puts each ticket in.
    private const string TICKET_ROWS_STEP = '0197a3c0-0000-7000-8000-00000000f0d0';
    private const string EMPTY_STEP = '0197a3c0-0000-7000-8000-00000000c941';

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
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        self::getContainer()->get(TenantContext::class)->reset();
        parent::tearDown();
    }

    #[Test]
    public function itCountsOnlyTheOpenTicketsOfTheCurrentOrganizationInTheStep(): void
    {
        self::assertSame(2, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int => $this->stepOccupancy()->openTicketsIn(self::TICKET_ROWS_STEP)));
    }

    #[Test]
    public function aStepWithoutTicketsCountsZero(): void
    {
        self::assertSame(0, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int => $this->stepOccupancy()->openTicketsIn(self::EMPTY_STEP)));
    }

    #[Test]
    public function withNoTransactionOpenTheCountIsRefusedRatherThanZero(): void
    {
        $this->expectExceptionObject(new \LogicException('Open tickets are counted in a transaction under the organization as tenant; none is open.'));

        $this->stepOccupancy()->openTicketsIn(self::TICKET_ROWS_STEP);
    }

    #[Test]
    public function inATransactionWithNoTenantTheCountIsRefusedRatherThanZero(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $connection->beginTransaction();
        try {
            $this->expectException(TenantNotSet::class);
            $this->stepOccupancy()->openTicketsIn(self::TICKET_ROWS_STEP);
        } finally {
            $connection->rollBack();
        }
    }

    private function stepOccupancy(): TicketStepOccupancy
    {
        return new TicketStepOccupancy(self::getContainer()->get('doctrine.dbal.default_connection'), self::getContainer()->get(TenantContext::class));
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

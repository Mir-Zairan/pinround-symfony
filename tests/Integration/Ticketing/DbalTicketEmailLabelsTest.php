<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Tenancy\OwnConnectionTransactions;
use App\Ticketing\Api\TicketEmailLabel;
use App\Ticketing\Api\TicketEmailLabels;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketEmailLabels;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalTicketEmailLabels as pinround_app on committed tickets of two organizations: one for everyone and one
 * restricted to its team in Acme Foods, one in Beta Bakery.
 */
final class DbalTicketEmailLabelsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000088a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000088a2';
    private const string ACME_PROJECT = '0197a3c0-0000-7000-8000-0000000088c1';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-0000000088c2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000088e1';
    private const string FOR_EVERYONE_TICKET = '0197a3c0-0000-7000-8000-0000000088d1';
    private const string TEAM_ONLY_TICKET = '0197a3c0-0000-7000-8000-0000000088d2';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-0000000088d3';
    private const string UNKNOWN_TICKET = '0197a3c0-0000-7000-8000-0000000088d9';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000088b1';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::FOR_EVERYONE_TICKET, self::ACME_PROJECT, 12, 'all', null, self::LENA);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::TEAM_ONLY_TICKET, self::ACME_PROJECT, 13, 'team', self::SHOP_TEAM, self::LENA);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, self::LENA, 7);
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        self::getContainer()->get(TenantContext::class)->reset();
        parent::tearDown();
    }

    /**
     * Both Acme Foods tickets carry the same title, so only the visibility column can tell which one keeps it.
     */
    #[Test]
    public function onlyATicketForEveryoneGivesItsTitle(): void
    {
        $labels = $this->labelsAs(self::ACME_FOODS, [self::TEAM_ONLY_TICKET, self::FOR_EVERYONE_TICKET, self::TEAM_ONLY_TICKET]);

        self::assertEquals([
            new TicketEmailLabel(new TicketId(self::TEAM_ONLY_TICKET), 'SHOP-13', new TeamId(self::SHOP_TEAM), null),
            new TicketEmailLabel(new TicketId(self::FOR_EVERYONE_TICKET), 'SHOP-12', null, 'Checkout button is hidden'),
        ], $labels);
    }

    #[Test]
    public function anotherOrganizationsTicketOrNoneIsLeftOut(): void
    {
        $labels = $this->labelsAs(self::ACME_FOODS, [self::BETA_TICKET, self::UNKNOWN_TICKET, self::FOR_EVERYONE_TICKET]);

        self::assertSame([self::FOR_EVERYONE_TICKET], array_map(static fn (TicketEmailLabel $label): string => $label->ticketId->toString(), $labels));
    }

    /**
     * The SQL names Acme Foods while the transaction's tenant is Beta Bakery, so only row-level security hides the
     * Acme Foods ticket; the same query under Acme Foods finds it.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsTicket(): void
    {
        $labelsOfAcmeTicket = static fn (Connection $connection): array => new DbalTicketEmailLabels($connection, self::getContainer()->get(TenantContext::class))
            ->labelsOf([new TicketId(self::FOR_EVERYONE_TICKET)]);
        self::assertCount(1, $this->ownTransactions()->rolledBackAs(self::ACME_FOODS, $labelsOfAcmeTicket));

        self::assertSame([], $this->ownTransactions()->rolledBackUnderAnotherContext(self::BETA_BAKERY, self::ACME_FOODS, $labelsOfAcmeTicket));
    }

    #[Test]
    public function anEmptyListGivesNoLabel(): void
    {
        self::assertSame([], $this->labelsAs(self::ACME_FOODS, []));
    }

    /**
     * No tenant and no transaction either, so the cap is the first refusal.
     */
    #[Test]
    public function moreIdsThanTheCapAreRefusedBeforeAnyQuery(): void
    {
        $ticketIds = array_map(static fn (int $number): TicketId => new TicketId(\sprintf('0197a3c0-0000-7000-8000-0000000089%02x', $number)), range(0, TicketEmailLabels::MAX_TICKET_IDS));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At most 100 ticket labels are read at once.');

        $this->queries()->labelsOf($ticketIds);
    }

    #[Test]
    public function theCapItselfIsRead(): void
    {
        $ticketIds = array_map(static fn (int $number): TicketId => new TicketId(\sprintf('0197a3c0-0000-7000-8000-0000000089%02x', $number)), range(1, TicketEmailLabels::MAX_TICKET_IDS - 1));

        self::assertCount(1, $this->labelsAs(self::ACME_FOODS, [self::FOR_EVERYONE_TICKET, ...array_map(static fn (TicketId $ticketId): string => $ticketId->toString(), $ticketIds)]));
    }

    #[Test]
    public function noTenantIsRefused(): void
    {
        $this->expectException(TenantNotSet::class);

        $this->queries()->labelsOf([new TicketId(self::FOR_EVERYONE_TICKET)]);
    }

    #[Test]
    public function noTransactionIsRefused(): void
    {
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Ticket email labels are read in a transaction under the organization as tenant; none is open.');

        $this->queries()->labelsOf([new TicketId(self::FOR_EVERYONE_TICKET)]);
    }

    /**
     * @param list<string> $ticketIds
     *
     * @return list<TicketEmailLabel>
     */
    private function labelsAs(string $organizationId, array $ticketIds): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->queries()->labelsOf(
            array_map(static fn (string $ticketId): TicketId => new TicketId($ticketId), $ticketIds),
        ));
    }

    private function queries(): DbalTicketEmailLabels
    {
        return new DbalTicketEmailLabels(self::getContainer()->get('doctrine.dbal.default_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function ownTransactions(): OwnConnectionTransactions
    {
        return new OwnConnectionTransactions(self::getContainer()->get('doctrine.dbal.default_connection'), self::getContainer()->get(TenantContext::class));
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

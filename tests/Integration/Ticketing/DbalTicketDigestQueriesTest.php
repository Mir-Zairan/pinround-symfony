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
use App\Ticketing\Api\DigestTicket;
use App\Ticketing\Api\TicketDigestQueries;
use App\Ticketing\Infrastructure\Doctrine\DbalTicketDigestQueries;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalTicketDigestQueries as pinround_app on committed tickets of two organizations: one for everyone and one
 * restricted to its team in Acme Foods, one in Beta Bakery.
 */
final class DbalTicketDigestQueriesTest extends KernelTestCase
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
        $lines = $this->linesAs(self::ACME_FOODS, [self::TEAM_ONLY_TICKET, self::FOR_EVERYONE_TICKET, self::TEAM_ONLY_TICKET]);

        self::assertEquals([
            new DigestTicket(new TicketId(self::TEAM_ONLY_TICKET), 'SHOP-13', new TeamId(self::SHOP_TEAM), null),
            new DigestTicket(new TicketId(self::FOR_EVERYONE_TICKET), 'SHOP-12', null, 'Checkout button is hidden'),
        ], $lines);
    }

    #[Test]
    public function anotherOrganizationsTicketOrNoneIsLeftOut(): void
    {
        $lines = $this->linesAs(self::ACME_FOODS, [self::BETA_TICKET, self::UNKNOWN_TICKET, self::FOR_EVERYONE_TICKET]);

        self::assertSame([self::FOR_EVERYONE_TICKET], array_map(static fn (DigestTicket $line): string => $line->ticketId->toString(), $lines));
    }

    /**
     * The SQL names Acme Foods while the transaction's tenant is Beta Bakery, so only row-level security hides the
     * Acme Foods ticket; the same query under Acme Foods finds it.
     */
    #[Test]
    public function rowLevelSecurityAloneHidesAnotherOrganizationsTicket(): void
    {
        $linesOfAcmeTicket = static fn (Connection $connection): array => new DbalTicketDigestQueries($connection, self::getContainer()->get(TenantContext::class))
            ->linesOf([new TicketId(self::FOR_EVERYONE_TICKET)]);
        self::assertCount(1, $this->ownTransactions()->rolledBackAs(self::ACME_FOODS, $linesOfAcmeTicket));

        self::assertSame([], $this->ownTransactions()->rolledBackUnderAnotherContext(self::BETA_BAKERY, self::ACME_FOODS, $linesOfAcmeTicket));
    }

    #[Test]
    public function anEmptyListGivesNoLine(): void
    {
        self::assertSame([], $this->linesAs(self::ACME_FOODS, []));
    }

    /**
     * No tenant and no transaction either, so the cap is the first refusal.
     */
    #[Test]
    public function moreIdsThanTheCapAreRefusedBeforeAnyQuery(): void
    {
        $ticketIds = array_map(static fn (int $number): TicketId => new TicketId(\sprintf('0197a3c0-0000-7000-8000-0000000089%02x', $number)), range(0, TicketDigestQueries::MAX_TICKET_IDS));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At most 100 tickets are read for a digest at once.');

        $this->queries()->linesOf($ticketIds);
    }

    #[Test]
    public function theCapItselfIsRead(): void
    {
        $ticketIds = array_map(static fn (int $number): TicketId => new TicketId(\sprintf('0197a3c0-0000-7000-8000-0000000089%02x', $number)), range(1, TicketDigestQueries::MAX_TICKET_IDS - 1));

        self::assertCount(1, $this->linesAs(self::ACME_FOODS, [self::FOR_EVERYONE_TICKET, ...array_map(static fn (TicketId $ticketId): string => $ticketId->toString(), $ticketIds)]));
    }

    #[Test]
    public function noTenantIsRefused(): void
    {
        $this->expectException(TenantNotSet::class);

        $this->queries()->linesOf([new TicketId(self::FOR_EVERYONE_TICKET)]);
    }

    #[Test]
    public function noTransactionIsRefused(): void
    {
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::ACME_FOODS));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Digest tickets are read in a transaction under the organization as tenant; none is open.');

        $this->queries()->linesOf([new TicketId(self::FOR_EVERYONE_TICKET)]);
    }

    /**
     * @param list<string> $ticketIds
     *
     * @return list<DigestTicket>
     */
    private function linesAs(string $organizationId, array $ticketIds): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->queries()->linesOf(
            array_map(static fn (string $ticketId): TicketId => new TicketId($ticketId), $ticketIds),
        ));
    }

    private function queries(): DbalTicketDigestQueries
    {
        return new DbalTicketDigestQueries(self::getContainer()->get('doctrine.dbal.default_connection'), self::getContainer()->get(TenantContext::class));
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

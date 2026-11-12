<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\TeamPeopleQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Api's TeamPeopleQueries as the container wires it (through TeamPeopleCache) on committed rows of two
 * organizations. A row written straight into a table publishes no event, so a read that still misses it after such a
 * write was answered from the cache.
 */
final class CachedTeamPeopleQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000002601';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000002602';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000002611';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-000000002612';
    // By user id the viewer comes first and the lead last, so neither the insert order nor the role orders the list.
    private const string RAJ = '0197a3c0-0000-7000-8000-000000002620';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000002621';
    private const string MIA = '0197a3c0-0000-7000-8000-000000002622';
    private const string LENA = '0197a3c0-0000-7000-8000-000000002623';

    protected function setUp(): void
    {
        $this->tearDownRows();
        $this->shopTeamRows()->add(self::BETA_BAKERY, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena Berg', 'lead', 'developer'],
            self::MIA => ['Mia Lund', 'member', 'tester'],
            self::RAJ => ['Raj Patel', 'viewer', 'other'],
            self::OMAR => ['Omar Demir', 'member', 'developer'],
        ]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->tearDownRows();
        parent::tearDown();
    }

    #[Test]
    public function theTeamsLeadAndMembersComeInUserIdOrderWithViewersLeftOutAndAreKept(): void
    {
        self::assertSame(['Omar', 'Mia', 'Lena'], $this->firstNamesOfShopAs(self::BETA_BAKERY));

        self::getContainer()->get('doctrine.dbal.migrator_connection')->executeStatement('UPDATE idn_user SET name = ? WHERE id = ?', ['Omer Demir', self::OMAR]);

        self::assertSame(['Omar', 'Mia', 'Lena'], $this->firstNamesOfShopAs(self::BETA_BAKERY), 'The second read came from the cache.');
    }

    #[Test]
    public function anotherOrganizationGetsNoneOfTheTeamsPeopleColdOrWarm(): void
    {
        self::assertSame(['Omar', 'Mia', 'Lena'], $this->firstNamesOfShopAs(self::BETA_BAKERY));

        self::assertSame([], $this->firstNamesOfShopAs(self::ACME_FOODS), 'Cold: Acme Foods has no entry yet.');
        self::assertSame([], $this->firstNamesOfShopAs(self::ACME_FOODS), 'Warm: Acme Foods\' own empty entry.');
        self::assertSame(['Omar', 'Mia', 'Lena'], $this->firstNamesOfShopAs(self::BETA_BAKERY), 'Acme Foods\' entry left Beta Bakery\'s alone.');
    }

    /**
     * @return list<string>
     */
    private function firstNamesOfShopAs(string $organizationId): array
    {
        $people = $this->transactions()->committedAs($organizationId, fn (): array => $this->teamPeopleQueries()->namedPossibleAssigneesOf(new TeamId(self::SHOP_TEAM)));

        return array_map(static fn (PossibleAssignee $person): string => $person->firstName, $people);
    }

    private function teamPeopleQueries(): TeamPeopleQueries
    {
        return self::getContainer()->get(TeamPeopleQueries::class);
    }

    private function tearDownRows(): void
    {
        $this->shopTeamRows()->removeAllOf([self::RAJ, self::OMAR, self::MIA, self::LENA], self::ACME_FOODS, self::BETA_BAKERY);
    }

    private function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }
}

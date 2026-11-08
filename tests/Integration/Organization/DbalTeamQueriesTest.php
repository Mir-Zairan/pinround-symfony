<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Infrastructure\Doctrine\DbalTeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalTeamQueries as pinround_app on committed org_team rows, in a transaction under a tenant, as inside a request.
 */
final class DbalTeamQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000005a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000005b1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000005c1';
    // Names run in the opposite order of the ids, and the lowercase "mobile" sorts after "Shop" by bytes but before it
    // ignoring case.
    private const string ACME_SHOP = '0197a3c0-0000-7000-8000-0000000005d1';
    private const string ACME_PAYMENTS = '0197a3c0-0000-7000-8000-0000000005d2';
    private const string ACME_MOBILE = '0197a3c0-0000-7000-8000-0000000005d3';
    private const string ACME_ANALYTICS = '0197a3c0-0000-7000-8000-0000000005d4';
    private const string BETA_CHECKOUT = '0197a3c0-0000-7000-8000-0000000005d5';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->transactions()->committedAs(self::BETA_BAKERY, function (): void {
            $this->addMembership(self::BETA_BAKERY);
            $this->addTeam(self::BETA_BAKERY, self::BETA_CHECKOUT, 'Checkout');
        });
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addMembership(self::ACME_FOODS);
            $this->addTeam(self::ACME_FOODS, self::ACME_SHOP, 'Shop');
            $this->addTeam(self::ACME_FOODS, self::ACME_PAYMENTS, 'Payments');
            $this->addTeam(self::ACME_FOODS, self::ACME_MOBILE, 'mobile');
            $this->addTeam(self::ACME_FOODS, self::ACME_ANALYTICS, 'Analytics');
        });
    }

    protected function tearDown(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function summariesOfGivesIdAndNameOfTheAskedTeamsOrderedByNameIgnoringCase(): void
    {
        self::assertSame(
            [
                [self::ACME_ANALYTICS, 'Analytics'],
                [self::ACME_MOBILE, 'mobile'],
                [self::ACME_PAYMENTS, 'Payments'],
                [self::ACME_SHOP, 'Shop'],
            ],
            $this->summaries(self::ACME_FOODS, [self::ACME_SHOP, self::ACME_MOBILE, self::ACME_PAYMENTS, self::ACME_ANALYTICS]),
        );
    }

    #[Test]
    public function aTeamNotAskedForIsNotReturned(): void
    {
        self::assertSame(
            [[self::ACME_PAYMENTS, 'Payments']],
            $this->summaries(self::ACME_FOODS, [self::ACME_PAYMENTS]),
        );
    }

    #[Test]
    public function aTeamOfAnotherOrganizationAskedByIdIsNotReturned(): void
    {
        // Under Acme Foods asking for Acme's Shop and Beta Bakery's Checkout, and asking Beta's tenant for Beta's own
        // team shows that the row is there.
        self::assertSame([[self::ACME_SHOP, 'Shop']], $this->summaries(self::ACME_FOODS, [self::ACME_SHOP, self::BETA_CHECKOUT]));
        self::assertSame([], $this->summaries(self::ACME_FOODS, [self::BETA_CHECKOUT]));
        self::assertSame([[self::BETA_CHECKOUT, 'Checkout']], $this->summaries(self::BETA_BAKERY, [self::BETA_CHECKOUT]));
    }

    #[Test]
    public function summariesOfFindsNothingUnderAnotherTenantOrNone(): void
    {
        $asked = new OrganizationId(self::BETA_BAKERY);
        $teamIds = [new TeamId(self::BETA_CHECKOUT)];

        self::assertSame([], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->summariesOf($asked, $teamIds)));
        self::assertSame([], $this->queries()->summariesOf($asked, $teamIds));
    }

    #[Test]
    public function anUnknownTeamIdIsNotReturned(): void
    {
        self::assertSame([], $this->summaries(self::ACME_FOODS, ['0197a3c0-0000-7000-8000-0000000005ff']));
    }

    #[Test]
    public function teamsOfGivesExactlyTheOrganizationsTeamsInIdOrder(): void
    {
        self::assertSame(
            [self::ACME_SHOP, self::ACME_PAYMENTS, self::ACME_MOBILE, self::ACME_ANALYTICS],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => self::teamIdStrings($this->queries()->teamsOf(new OrganizationId(self::ACME_FOODS)))),
        );
        self::assertSame(
            [self::BETA_CHECKOUT],
            $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => self::teamIdStrings($this->queries()->teamsOf(new OrganizationId(self::BETA_BAKERY)))),
        );
    }

    #[Test]
    public function teamsOfFindsNothingUnderAnotherTenant(): void
    {
        $betaTeams = fn (): array => self::teamIdStrings($this->queries()->teamsOf(new OrganizationId(self::BETA_BAKERY)));

        // Under Beta Bakery itself the team is there, so the empty answer below comes from row-level security.
        self::assertSame([self::BETA_CHECKOUT], $this->transactions()->rolledBackAs(self::BETA_BAKERY, $betaTeams));
        self::assertSame([], $this->transactions()->rolledBackAs(self::ACME_FOODS, $betaTeams));
    }

    #[Test]
    public function teamsOfFindsNothingWithNoTenant(): void
    {
        self::assertSame([], $this->queries()->teamsOf(new OrganizationId(self::ACME_FOODS)));
    }

    #[Test]
    public function allSummariesOfGivesEveryTeamOfTheOrganizationOrderedByNameIgnoringCase(): void
    {
        $summariesOf = fn (string $tenantId): array => $this->transactions()->rolledBackAs($tenantId, fn (): array => array_map(
            static fn ($summary): array => [$summary->id()->toString(), $summary->name()],
            $this->queries()->allSummariesOf(new OrganizationId($tenantId)),
        ));

        self::assertSame(
            [[self::ACME_ANALYTICS, 'Analytics'], [self::ACME_MOBILE, 'mobile'], [self::ACME_PAYMENTS, 'Payments'], [self::ACME_SHOP, 'Shop']],
            $summariesOf(self::ACME_FOODS),
        );
        self::assertSame([[self::BETA_CHECKOUT, 'Checkout']], $summariesOf(self::BETA_BAKERY));
    }

    #[Test]
    public function allSummariesOfFindsNothingUnderAnotherTenantOrNone(): void
    {
        $asked = new OrganizationId(self::BETA_BAKERY);

        self::assertSame([], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->allSummariesOf($asked)));
        self::assertSame([], $this->queries()->allSummariesOf($asked));
    }

    #[Test]
    public function countOfCountsTheOrganizationsTeamsOnly(): void
    {
        $countOf = fn (string $tenantId, string $askedId): int => $this->transactions()->rolledBackAs($tenantId, fn (): int => $this->queries()->countOf(new OrganizationId($askedId)));

        self::assertSame(4, $countOf(self::ACME_FOODS, self::ACME_FOODS));
        self::assertSame(1, $countOf(self::BETA_BAKERY, self::BETA_BAKERY));
        self::assertSame(0, $countOf(self::ACME_FOODS, self::BETA_BAKERY), 'another tenant sees nothing');
        self::assertSame(0, $this->queries()->countOf(new OrganizationId(self::ACME_FOODS)), 'no tenant sees nothing');
    }

    /**
     * @param list<string> $teamIds
     *
     * @return list<array{string, string}> id and name of each team returned
     */
    private function summaries(string $tenantId, array $teamIds): array
    {
        return $this->transactions()->rolledBackAs($tenantId, fn (): array => array_map(
            static fn ($summary): array => [$summary->id()->toString(), $summary->name()],
            $this->queries()->summariesOf(new OrganizationId($tenantId), array_map(static fn (string $teamId): TeamId => new TeamId($teamId), $teamIds)),
        ));
    }

    /**
     * @param list<TeamId> $teamIds
     *
     * @return list<string>
     */
    private static function teamIdStrings(array $teamIds): array
    {
        return array_map(static fn (TeamId $teamId): string => $teamId->toString(), $teamIds);
    }

    private function queries(): DbalTeamQueries
    {
        return new DbalTeamQueries($this->connection());
    }

    private function addMembership(string $organizationId): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2027-03-15 10:00:00+00')",
            [$organizationId, self::LENA],
        );
    }

    private function addTeam(string $organizationId, string $teamId, string $name): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_team (organization_id, id, name, lead_user_id, created_at) VALUES (?, ?, ?, ?, '2027-03-15 10:00:00+00')",
            [$organizationId, $teamId, $name, self::LENA],
        );
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}

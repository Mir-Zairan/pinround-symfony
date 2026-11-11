<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Api\MemberJob;
use App\Organization\Api\TeamSummary;
use App\Organization\Domain\TeamSlug;
use App\Organization\Infrastructure\Doctrine\DbalTeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
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
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000005c2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000005c3';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000005c4';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000005c5';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->transactions()->committedAs(self::BETA_BAKERY, function (): void {
            $this->addMembership(self::BETA_BAKERY);
            $this->addTeam(self::BETA_BAKERY, self::BETA_CHECKOUT, 'Checkout', self::BEN);
            // Mia works in both organizations, with another job in Beta Bakery.
            $this->addTeamMember(self::BETA_BAKERY, self::BETA_CHECKOUT, self::MIA, 'developer');
        });
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addMembership(self::ACME_FOODS);
            $this->addTeam(self::ACME_FOODS, self::ACME_SHOP, 'Shop');
            $this->addTeam(self::ACME_FOODS, self::ACME_PAYMENTS, 'Payments');
            $this->addTeam(self::ACME_FOODS, self::ACME_MOBILE, 'mobile');
            $this->addTeam(self::ACME_FOODS, self::ACME_ANALYTICS, 'Analytics');
            $this->addTeamMember(self::ACME_FOODS, self::ACME_SHOP, self::MIA, 'tester');
            $this->addTeamMember(self::ACME_FOODS, self::ACME_SHOP, self::OMAR, 'developer');
            $this->addTeamMember(self::ACME_FOODS, self::ACME_MOBILE, self::OMAR, 'other');
            // Added after Omar, though Lena's id is lower; Sara views Shop with a job that would make her its second tester.
            $this->addTeamMember(self::ACME_FOODS, self::ACME_SHOP, self::LENA, 'developer', 'lead');
            $this->addTeamMember(self::ACME_FOODS, self::ACME_SHOP, self::SARA, 'tester', 'viewer');
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

    #[Test]
    public function summariesCarryEachTeamsSlug(): void
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $slugs = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            self::slugsOf($this->queries()->allSummariesOf($acmeFoods)),
            self::slugsOf($this->queries()->summariesOf($acmeFoods, [new TeamId(self::ACME_SHOP), new TeamId(self::ACME_MOBILE)])),
        ]);

        self::assertSame([['analytics', 'mobile', 'payments', 'shop'], ['mobile', 'shop']], $slugs);
    }

    #[Test]
    public function bySlugFindsTheTeamOfTheOrganizationWithThatSlug(): void
    {
        self::assertSame([self::ACME_PAYMENTS, 'Payments', 'payments'], $this->bySlugAs(self::ACME_FOODS, self::ACME_FOODS, 'payments'));
        self::assertSame([self::BETA_CHECKOUT, 'Checkout', 'checkout'], $this->bySlugAs(self::BETA_BAKERY, self::BETA_BAKERY, 'checkout'));
    }

    #[Test]
    public function bySlugIsNullForASlugNoTeamOfTheOrganizationHas(): void
    {
        self::assertNull($this->bySlugAs(self::ACME_FOODS, self::ACME_FOODS, 'support'));
        self::assertNull($this->bySlugAs(self::ACME_FOODS, self::ACME_FOODS, 'Shop'), 'a slug is matched exactly');
    }

    #[Test]
    public function bySlugIsNullForATeamOfAnotherOrganizationOrNoTenant(): void
    {
        // Beta Bakery's own tenant finds Checkout (bySlugFindsTheTeamOfTheOrganizationWithThatSlug), so these nulls come
        // from the organization asked and from row-level security.
        self::assertNull($this->bySlugAs(self::ACME_FOODS, self::ACME_FOODS, 'checkout'));
        self::assertNull($this->bySlugAs(self::ACME_FOODS, self::BETA_BAKERY, 'checkout'));
        self::assertNull($this->queries()->bySlug(new OrganizationId(self::BETA_BAKERY), 'checkout'));
    }

    #[Test]
    public function jobOfGivesThePersonsJobInThatTeam(): void
    {
        self::assertSame(MemberJob::Tester, $this->jobAs(self::ACME_FOODS, self::ACME_SHOP, self::MIA));
        self::assertSame(MemberJob::Developer, $this->jobAs(self::ACME_FOODS, self::ACME_SHOP, self::OMAR));
        self::assertSame(MemberJob::Other, $this->jobAs(self::ACME_FOODS, self::ACME_MOBILE, self::OMAR));
    }

    #[Test]
    public function jobOfIsNullForAPersonNotInTheTeam(): void
    {
        self::assertNull($this->jobAs(self::ACME_FOODS, self::ACME_MOBILE, self::MIA));
        self::assertNull($this->jobAs(self::ACME_FOODS, self::ACME_PAYMENTS, self::OMAR));
    }

    #[Test]
    public function jobOfIsNullForATeamOfAnotherOrganizationOrNoTenant(): void
    {
        // Under Beta Bakery itself Mia has a job in Checkout, so the nulls below come from row-level security.
        self::assertSame(MemberJob::Developer, $this->jobAs(self::BETA_BAKERY, self::BETA_CHECKOUT, self::MIA));
        self::assertNull($this->jobAs(self::ACME_FOODS, self::BETA_CHECKOUT, self::MIA));
        self::assertNull($this->queries()->jobOf(new TeamId(self::ACME_SHOP), new UserId(self::MIA)));
    }

    #[Test]
    public function leadOfGivesTheTeamsLead(): void
    {
        self::assertSame(self::LENA, $this->leadAs(self::ACME_FOODS, self::ACME_SHOP));
        self::assertSame(self::BEN, $this->leadAs(self::BETA_BAKERY, self::BETA_CHECKOUT));
    }

    #[Test]
    public function leadOfIsNullForATeamOfAnotherOrganizationAnUnknownTeamOrNoTenant(): void
    {
        self::assertNull($this->leadAs(self::ACME_FOODS, self::BETA_CHECKOUT));
        self::assertNull($this->leadAs(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000005df'));
        self::assertNull($this->leadAs(null, self::ACME_SHOP));
    }

    #[Test]
    public function peopleByJobGivesTheLeadAndMembersByJobInIdOrderWithoutViewers(): void
    {
        self::assertSame(
            ['developer' => [self::LENA, self::OMAR], 'tester' => [self::MIA], 'other' => []],
            $this->peopleByJobAs(self::ACME_FOODS, self::ACME_SHOP),
        );
        self::assertSame(['developer' => [], 'tester' => [], 'other' => [self::OMAR]], $this->peopleByJobAs(self::ACME_FOODS, self::ACME_MOBILE));
    }

    #[Test]
    public function peopleByJobFindsNoOneForATeamOfAnotherOrganizationAnUnknownTeamOrNoTenant(): void
    {
        $noOne = ['developer' => [], 'tester' => [], 'other' => []];
        // Under Beta Bakery itself Mia develops in Checkout, so the empty lists below come from row-level security.
        self::assertSame(['developer' => [self::MIA], 'tester' => [], 'other' => []], $this->peopleByJobAs(self::BETA_BAKERY, self::BETA_CHECKOUT));
        self::assertSame($noOne, $this->peopleByJobAs(self::ACME_FOODS, self::BETA_CHECKOUT));
        self::assertSame($noOne, $this->peopleByJobAs(self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000005df'));
        self::assertSame($noOne, $this->peopleByJobAs(null, self::ACME_SHOP));
    }

    /**
     * @param list<TeamSummary> $summaries
     *
     * @return list<string>
     */
    private static function slugsOf(array $summaries): array
    {
        return array_map(static fn (TeamSummary $summary): string => $summary->slug(), $summaries);
    }

    /**
     * @return array{string, string, string}|null id, name and slug of the team found
     */
    private function bySlugAs(string $tenantId, string $askedId, string $slug): ?array
    {
        return $this->transactions()->rolledBackAs($tenantId, function () use ($askedId, $slug): ?array {
            $team = $this->queries()->bySlug(new OrganizationId($askedId), $slug);

            return null === $team ? null : [$team->id()->toString(), $team->name(), $team->slug()];
        });
    }

    private function jobAs(string $tenantId, string $teamId, string $userId): ?MemberJob
    {
        return $this->transactions()->rolledBackAs($tenantId, fn (): ?MemberJob => $this->queries()->jobOf(new TeamId($teamId), new UserId($userId)));
    }

    /**
     * @return array<string, list<string>> user id strings by job
     */
    private function peopleByJobAs(?string $tenantId, string $teamId): array
    {
        $read = fn (): array => array_map(
            static fn (array $userIds): array => array_map(static fn (UserId $userId): string => $userId->toString(), $userIds),
            $this->queries()->peopleByJob(new TeamId($teamId)),
        );

        return null === $tenantId ? $read() : $this->transactions()->rolledBackAs($tenantId, $read);
    }

    private function leadAs(?string $tenantId, string $teamId): ?string
    {
        $read = fn (): ?string => $this->queries()->leadOf(new TeamId($teamId))?->toString();

        return null === $tenantId ? $read() : $this->transactions()->rolledBackAs($tenantId, $read);
    }

    private function addTeamMember(string $organizationId, string $teamId, string $userId, string $job, string $teamRole = 'member'): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES (?, ?, ?, ?, ?, '2027-03-15 10:00:00+00')",
            [$organizationId, $teamId, $userId, $teamRole, $job],
        );
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

    private function addTeam(string $organizationId, string $teamId, string $name, string $leadId = self::LENA): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, ?, ?, ?, ?, '2027-03-15 10:00:00+00')",
            [$organizationId, $teamId, $name, TeamSlug::fromName($name)->toString(), $leadId],
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

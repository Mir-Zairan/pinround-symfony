<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Api\Roles;
use App\Organization\Infrastructure\Doctrine\DbalMembershipQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalMembershipQueries as pinround_app on committed org_ rows: organizationsOf with no tenant set, as before a
 * request has one; rolesOf and teamsOf in a transaction under a tenant, as inside a request.
 */
final class DbalMembershipQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000004a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000004b1';
    // Team A is added first but has the higher id, so id order differs from the order the rows were added in.
    private const string ACME_TEAM_A = '0197a3c0-0000-7000-8000-0000000004d2';
    private const string ACME_TEAM_B = '0197a3c0-0000-7000-8000-0000000004d1';
    private const string BETA_TEAM = '0197a3c0-0000-7000-8000-0000000004d3';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000004c1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000004c2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000004c3';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000004c4';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000004c5';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        // Beta Bakery first, so the result's id order differs from the order the rows were added in.
        $this->transactions()->committedAs(self::BETA_BAKERY, function (): void {
            $this->addMembership(self::BETA_BAKERY, self::LENA, '{admin}');
            $this->addMembership(self::BETA_BAKERY, self::OMAR, '{}');
            $this->addTeam(self::BETA_BAKERY, self::BETA_TEAM, 'Checkout', self::LENA);
            $this->addTeamMember(self::BETA_BAKERY, self::BETA_TEAM, self::LENA, 'lead');
        });
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addMembership(self::ACME_FOODS, self::LENA, '{}');
            $this->addMembership(self::ACME_FOODS, self::SARA, '{admin}');
            $this->addMembership(self::ACME_FOODS, self::RAJ, '{manager}');
            $this->addTeam(self::ACME_FOODS, self::ACME_TEAM_A, 'Shop', self::LENA);
            $this->addTeam(self::ACME_FOODS, self::ACME_TEAM_B, 'Payments', self::RAJ);
            $this->addTeamMember(self::ACME_FOODS, self::ACME_TEAM_A, self::LENA, 'lead');
            $this->addTeamMember(self::ACME_FOODS, self::ACME_TEAM_A, self::RAJ, 'viewer');
            $this->addTeamMember(self::ACME_FOODS, self::ACME_TEAM_B, self::RAJ, 'lead');
            $this->addTeamMember(self::ACME_FOODS, self::ACME_TEAM_B, self::LENA, 'member');
        });
    }

    protected function tearDown(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function aUserInTwoOrganizationsGetsBothIdsInIdOrder(): void
    {
        self::assertSame([self::ACME_FOODS, self::BETA_BAKERY], $this->organizationsOf(self::LENA));
        self::assertNull($this->tenantContext()->get());
    }

    #[Test]
    public function aUserGetsOnlyTheirOwnMemberships(): void
    {
        self::assertSame([self::BETA_BAKERY], $this->organizationsOf(self::OMAR));
    }

    #[Test]
    public function aUserInNoOrganizationGetsAnEmptyList(): void
    {
        self::assertSame([], $this->organizationsOf(self::MIA));
    }

    #[Test]
    public function anAdminWithNoTeamRoleIsAdminAndInNoTeam(): void
    {
        self::assertSame(
            ['isAdmin' => true, 'isManager' => false, 'teamIds' => [], 'leadTeamIds' => [], 'memberTeamIds' => [], 'viewerTeamIds' => []],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => self::describe($this->queries()->rolesOf(new UserId(self::SARA), new OrganizationId(self::ACME_FOODS)))),
        );
    }

    #[Test]
    public function aLeadOfOneTeamAndMemberOfAnotherLeadsOnlyTheFirstAndIsInBothInIdOrder(): void
    {
        self::assertSame(
            ['isAdmin' => false, 'isManager' => false, 'teamIds' => [self::ACME_TEAM_B, self::ACME_TEAM_A], 'leadTeamIds' => [self::ACME_TEAM_A], 'memberTeamIds' => [self::ACME_TEAM_B], 'viewerTeamIds' => []],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => self::describe($this->queries()->rolesOf(new UserId(self::LENA), new OrganizationId(self::ACME_FOODS)))),
        );
    }

    #[Test]
    public function aManagerWhoViewsOneTeamAndLeadsAnotherHasAllThree(): void
    {
        self::assertSame(
            ['isAdmin' => false, 'isManager' => true, 'teamIds' => [self::ACME_TEAM_B, self::ACME_TEAM_A], 'leadTeamIds' => [self::ACME_TEAM_B], 'memberTeamIds' => [], 'viewerTeamIds' => [self::ACME_TEAM_A]],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => self::describe($this->queries()->rolesOf(new UserId(self::RAJ), new OrganizationId(self::ACME_FOODS)))),
        );
    }

    #[Test]
    public function aUserWithNoMembershipHasNoRoles(): void
    {
        self::assertSame(
            self::describe(Roles::none()),
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => self::describe($this->queries()->rolesOf(new UserId(self::MIA), new OrganizationId(self::ACME_FOODS)))),
        );
    }

    #[Test]
    public function aTeamRowLeftWithoutAMembershipGivesNoRoles(): void
    {
        $roles = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->addTeamMember(self::ACME_FOODS, self::ACME_TEAM_A, self::MIA, 'member');

            return self::describe($this->queries()->rolesOf(new UserId(self::MIA), new OrganizationId(self::ACME_FOODS)));
        });

        self::assertSame(self::describe(Roles::none()), $roles);
    }

    #[Test]
    public function teamsOfGivesExactlyTheOrganizationsTeamsInIdOrder(): void
    {
        self::assertSame(
            [self::ACME_TEAM_B, self::ACME_TEAM_A],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => self::teamIdStrings($this->queries()->teamsOf(new OrganizationId(self::ACME_FOODS)))),
        );
        self::assertSame(
            [self::BETA_TEAM],
            $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => self::teamIdStrings($this->queries()->teamsOf(new OrganizationId(self::BETA_BAKERY)))),
        );
    }

    #[Test]
    public function underAnotherTenantRolesAndTeamsOfAnOrganizationAreInvisible(): void
    {
        $lenaInBeta = fn (): array => self::describe($this->queries()->rolesOf(new UserId(self::LENA), new OrganizationId(self::BETA_BAKERY)));
        $betaTeams = fn (): array => self::teamIdStrings($this->queries()->teamsOf(new OrganizationId(self::BETA_BAKERY)));

        // Under Beta Bakery itself the rows are there, so the empty answers below come from row-level security.
        self::assertSame(['isAdmin' => true, 'isManager' => false, 'teamIds' => [self::BETA_TEAM], 'leadTeamIds' => [self::BETA_TEAM], 'memberTeamIds' => [], 'viewerTeamIds' => []], $this->transactions()->rolledBackAs(self::BETA_BAKERY, $lenaInBeta));
        self::assertSame(self::describe(Roles::none()), $this->transactions()->rolledBackAs(self::ACME_FOODS, $lenaInBeta));
        self::assertSame([], $this->transactions()->rolledBackAs(self::ACME_FOODS, $betaTeams));
    }

    #[Test]
    public function withNoTenantRolesAndTeamsAreInvisible(): void
    {
        self::assertSame(self::describe(Roles::none()), self::describe($this->queries()->rolesOf(new UserId(self::SARA), new OrganizationId(self::ACME_FOODS))));
        self::assertSame([], $this->queries()->teamsOf(new OrganizationId(self::ACME_FOODS)));
    }

    #[Test]
    public function aRoleChangeShowsOnTheNextCallOfTheSameQueries(): void
    {
        $queries = $this->queries();
        $sara = new UserId(self::SARA);
        $lena = new UserId(self::LENA);
        $acmeFoods = new OrganizationId(self::ACME_FOODS);

        [$saraAfter, $lenaAfter] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function () use ($queries, $sara, $lena, $acmeFoods): array {
            self::assertTrue($queries->rolesOf($sara, $acmeFoods)->isAdmin());
            self::assertTrue($queries->rolesOf($lena, $acmeFoods)->isMemberOf(new TeamId(self::ACME_TEAM_B)));

            $this->connection()->executeStatement("UPDATE org_membership SET org_roles = '{manager}' WHERE organization_id = ? AND user_id = ?", [self::ACME_FOODS, self::SARA]);
            $this->connection()->executeStatement("UPDATE org_team_member SET team_role = 'viewer' WHERE organization_id = ? AND team_id = ? AND user_id = ?", [self::ACME_FOODS, self::ACME_TEAM_B, self::LENA]);

            return [self::describe($queries->rolesOf($sara, $acmeFoods)), self::describe($queries->rolesOf($lena, $acmeFoods))];
        });

        self::assertSame(['isAdmin' => false, 'isManager' => true, 'teamIds' => [], 'leadTeamIds' => [], 'memberTeamIds' => [], 'viewerTeamIds' => []], $saraAfter);
        self::assertSame(['isAdmin' => false, 'isManager' => false, 'teamIds' => [self::ACME_TEAM_B, self::ACME_TEAM_A], 'leadTeamIds' => [self::ACME_TEAM_A], 'memberTeamIds' => [], 'viewerTeamIds' => [self::ACME_TEAM_B]], $lenaAfter);
    }

    /**
     * @return array{isAdmin: bool, isManager: bool, teamIds: list<string>, leadTeamIds: list<string>, memberTeamIds: list<string>, viewerTeamIds: list<string>}
     */
    private static function describe(Roles $roles): array
    {
        return [
            'isAdmin' => $roles->isAdmin(),
            'isManager' => $roles->isManager(),
            'teamIds' => self::teamIdStrings($roles->teamIds()),
            'leadTeamIds' => self::teamIdStrings(array_values(array_filter($roles->teamIds(), $roles->isLeadOf(...)))),
            'memberTeamIds' => self::teamIdStrings(array_values(array_filter($roles->teamIds(), $roles->isMemberOf(...)))),
            'viewerTeamIds' => self::teamIdStrings(array_values(array_filter($roles->teamIds(), $roles->isViewerOf(...)))),
        ];
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

    /**
     * @return list<string>
     */
    private function organizationsOf(string $userId): array
    {
        return array_map(static fn (OrganizationId $organizationId): string => $organizationId->toString(), $this->queries()->organizationsOf(new UserId($userId)));
    }

    private function queries(): DbalMembershipQueries
    {
        return new DbalMembershipQueries($this->connection());
    }

    private function addMembership(string $organizationId, string $userId, string $orgRoles): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, ?, '2027-03-15 10:00:00+00')",
            [$organizationId, $userId, $orgRoles],
        );
    }

    private function addTeam(string $organizationId, string $teamId, string $name, string $leadUserId): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_team (organization_id, id, name, lead_user_id, created_at) VALUES (?, ?, ?, ?, '2027-03-15 10:00:00+00')",
            [$organizationId, $teamId, $name, $leadUserId],
        );
    }

    private function addTeamMember(string $organizationId, string $teamId, string $userId, string $teamRole): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES (?, ?, ?, ?, 'other', '2027-03-15 10:00:00+00')",
            [$organizationId, $teamId, $userId, $teamRole],
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

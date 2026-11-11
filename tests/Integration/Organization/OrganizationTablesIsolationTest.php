<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Domain\Invitation;
use App\Organization\Domain\Job;
use App\Organization\Domain\Membership;
use App\Organization\Domain\Organization;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Domain\OrgSettings;
use App\Organization\Domain\Team;
use App\Organization\Domain\TeamMember;
use App\Organization\Domain\TeamRole;
use App\Organization\Domain\TeamSlug;
use App\Organization\Infrastructure\Doctrine\DoctrineInvitations;
use App\Organization\Infrastructure\Doctrine\DoctrineMemberships;
use App\Organization\Infrastructure\Doctrine\DoctrineOrganizations;
use App\Organization\Infrastructure\Doctrine\DoctrineOrgSettingsStore;
use App\Organization\Infrastructure\Doctrine\DoctrineTeams;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Doctrine\TenantFilter;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves row-level security and the tenant filter on every org_ table (ADR-0005): with Acme Foods as tenant,
 * Beta Bakery's rows are invisible, with the filter on and off, and cannot be written, moved into or out of.
 */
final class OrganizationTablesIsolationTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000006a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000006b1';
    // An organization with no rows: the target of smuggled inserts and moved rows.
    private const string THIRD_ORG = '0197a3c0-0000-7000-8000-0000000006c1';
    private const string ACME_TEAM = '0197a3c0-0000-7000-8000-0000000006d1';
    private const string BETA_TEAM = '0197a3c0-0000-7000-8000-0000000006d2';
    private const string SARA = '0197a3c0-0000-7000-8000-000000000651';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000652';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000654';

    /**
     * Per table: the entity and the field holding its organization, a row for THIRD_ORG to smuggle in, a harmless
     * assignment for UPDATE, and whether pinround_app may DELETE.
     */
    private const array TABLES = [
        'org_organization' => [Organization::class, 'id', "INSERT INTO org_organization (organization_id, name, slug, status, created_at) VALUES ('".self::THIRD_ORG."', 'Smuggled', 'smuggled', 'active', now())", 'name = name', false],
        'org_membership' => [Membership::class, 'organizationId', "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES ('".self::THIRD_ORG."', '".self::SARA."', '{admin}', now())", 'joined_at = joined_at', true],
        'org_team' => [Team::class, 'organizationId', "INSERT INTO org_team (id, organization_id, name, slug, lead_user_id, created_at) VALUES ('0197a3c0-0000-7000-8000-0000000006d3', '".self::THIRD_ORG."', 'Smuggled', 'smuggled', '".self::SARA."', now())", 'name = name', false],
        'org_team_member' => [TeamMember::class, 'organizationId', "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES ('".self::THIRD_ORG."', '".self::BETA_TEAM."', '".self::SARA."', 'lead', 'other', now())", 'job = job', true],
        'org_settings' => [OrgSettings::class, 'organizationId', "INSERT INTO org_settings (organization_id, data, created_at) VALUES ('".self::THIRD_ORG."', '{}', now())", 'data = data', false],
        'org_invitation' => [Invitation::class, 'organizationId', "INSERT INTO org_invitation (organization_id, id, email, org_roles, invited_by, created_at, expires_at) VALUES ('".self::THIRD_ORG."', '0197a3c0-0000-7000-8000-0000000006e3', 'omar@acme-foods.example', '{}', '".self::SARA."', now(), now() + interval '72 hours')", 'email = email', true],
    ];

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY, self::THIRD_ORG);
        $this->addEveryKindOfRowFor(self::ACME_FOODS, self::ACME_TEAM, 'acme-foods');
        $this->addEveryKindOfRowFor(self::BETA_BAKERY, self::BETA_TEAM, 'beta-bakery');
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY, self::THIRD_ORG);
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function organizationTables(): iterable
    {
        foreach (array_keys(self::TABLES) as $table) {
            yield $table => [$table];
        }
    }

    #[Test]
    public function theCasesCoverEveryOrgTableInTheSchema(): void
    {
        self::assertEqualsCanonicalizing(
            array_keys(self::TABLES),
            $this->connection()->fetchFirstColumn("SELECT relname FROM pg_class WHERE relnamespace = 'public'::regnamespace AND relkind IN ('r', 'p') AND relname LIKE 'org\\_%'"),
        );
        self::assertEqualsCanonicalizing(array_keys(self::TABLES), OrganizationRows::TABLES);
    }

    #[Test]
    #[DataProvider('organizationTables')]
    public function sqlSeesOnlyTheTenantsRowsAndNoneWithoutATenant(string $table): void
    {
        $visibleOrganizations = fn (string $organizationId): array => $this->transactions()->rolledBackAs(
            $organizationId,
            fn (): array => $this->connection()->fetchFirstColumn(\sprintf('SELECT DISTINCT organization_id FROM %s', $table)),
        );

        self::assertSame([self::ACME_FOODS], $visibleOrganizations(self::ACME_FOODS));
        self::assertSame([self::BETA_BAKERY], $visibleOrganizations(self::BETA_BAKERY));
        self::assertSame(0, $this->connection()->fetchOne(\sprintf('SELECT count(*) FROM %s', $table)));
    }

    #[Test]
    #[DataProvider('organizationTables')]
    public function theOrmSeesOnlyTheTenantsRowsWithTheTenantFilterOnAndOff(string $table): void
    {
        [$entity, $organizationField] = self::TABLES[$table];
        $dql = \sprintf('SELECT DISTINCT e.%s FROM %s e', $organizationField, $entity);

        $withFilter = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->entityManager()->createQuery($dql)->getSingleColumnResult());
        $this->entityManager()->getFilters()->disable(TenantFilter::NAME);
        [$sql, $withoutFilter] = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            $this->entityManager()->createQuery($dql)->getSQL(),
            $this->entityManager()->createQuery($dql)->getSingleColumnResult(),
        ]);

        self::assertSame([self::ACME_FOODS], $withFilter);
        self::assertIsString($sql);
        self::assertStringNotContainsString('current_setting', $sql);
        self::assertSame([self::ACME_FOODS], $withoutFilter);
    }

    #[Test]
    #[DataProvider('organizationTables')]
    public function rowsOfAnotherOrganizationCannotBeAddedChangedMovedOrRemoved(string $table): void
    {
        [, , $insertForThirdOrg, $harmlessAssignment, $mayDelete] = self::TABLES[$table];
        $rowsOfBetaBakery = fn (): mixed => $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): mixed => $this->connection()->fetchOne(\sprintf('SELECT count(*) FROM %s', $table)));
        $betaRowsBefore = $rowsOfBetaBakery();

        $this->assertRefusedByRowLevelSecurity($table, $insertForThirdOrg);
        $this->assertRefusedByRowLevelSecurity($table, \sprintf("UPDATE %s SET organization_id = '%s' WHERE organization_id = '%s'", $table, self::THIRD_ORG, self::ACME_FOODS));
        self::assertSame(0, $this->asAcmeFoods(\sprintf("UPDATE %s SET %s WHERE organization_id = '%s'", $table, $harmlessAssignment, self::BETA_BAKERY)));
        if (true === $mayDelete) {
            self::assertSame(0, $this->asAcmeFoods(\sprintf("DELETE FROM %s WHERE organization_id = '%s'", $table, self::BETA_BAKERY)));
        }

        self::assertGreaterThan(0, $betaRowsBefore);
        self::assertSame($betaRowsBefore, $rowsOfBetaBakery());
    }

    /**
     * Foreign key checks skip row-level security, so only the composite key (organization_id, team_id) stops this.
     */
    #[Test]
    public function aTeamMemberRowCannotNameATeamOfAnotherOrganization(): void
    {
        try {
            $this->asAcmeFoods(\sprintf(
                "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES ('%s', '%s', '%s', 'member', 'other', now())",
                self::ACME_FOODS,
                self::BETA_TEAM,
                self::SARA,
            ));
            self::fail('An Acme Foods member row named a Beta Bakery team.');
        } catch (DriverException $exception) {
            self::assertSame('23503', $exception->getSQLState());
            self::assertStringContainsString('org_team_member_team_fkey', $exception->getMessage());
        }

        self::assertSame([], $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->connection()->fetchFirstColumn(
            'SELECT user_id FROM org_team_member WHERE team_id = ? AND user_id = ?',
            [self::BETA_TEAM, self::SARA],
        )));
    }

    /**
     * As for a team member row: only the composite key (organization_id, team_id) stops an invitation into another
     * organization's team.
     */
    #[Test]
    public function anInvitationCannotNameATeamOfAnotherOrganization(): void
    {
        try {
            $this->asAcmeFoods(\sprintf(
                "INSERT INTO org_invitation (organization_id, id, email, org_roles, team_id, team_role, invited_by, created_at, expires_at) VALUES ('%s', '0197a3c0-0000-7000-8000-0000000006e4', 'raj@acme-foods.example', '{}', '%s', 'member', '%s', now(), now() + interval '72 hours')",
                self::ACME_FOODS,
                self::BETA_TEAM,
                self::SARA,
            ));
            self::fail('An Acme Foods invitation named a Beta Bakery team.');
        } catch (DriverException $exception) {
            self::assertSame('23503', $exception->getSQLState());
            self::assertStringContainsString('org_invitation_team_fkey', $exception->getMessage());
        }

        self::assertSame([], $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->connection()->fetchFirstColumn(
            'SELECT id FROM org_invitation WHERE team_id = ? AND email = ?',
            [self::BETA_TEAM, 'raj@acme-foods.example'],
        )));
        self::assertSame([], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn(
            'SELECT id FROM org_invitation WHERE email = ?',
            ['raj@acme-foods.example'],
        )));
    }

    private function assertRefusedByRowLevelSecurity(string $table, string $sql): void
    {
        try {
            $this->asAcmeFoods($sql);
        } catch (DriverException $exception) {
            self::assertSame('42501', $exception->getSQLState());
            self::assertStringContainsString(\sprintf('new row violates row-level security policy for table "%s"', $table), $exception->getMessage());

            return;
        }

        self::fail(\sprintf('Row-level security did not refuse: %s', $sql));
    }

    private function asAcmeFoods(string $sql): int
    {
        return (int) $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement($sql));
    }

    private function addEveryKindOfRowFor(string $organizationId, string $teamId, string $slug): void
    {
        $at = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
        $this->transactions()->committedAs($organizationId, function () use ($organizationId, $teamId, $slug, $at): void {
            $organization = new OrganizationId($organizationId);
            $entityManager = $this->entityManager();
            new DoctrineOrganizations($entityManager)->add(new Organization($organization, $slug, $slug, $at));
            new DoctrineMemberships($entityManager)->add(new Membership($organization, new UserId(self::SARA), new OrgRoles(OrgRole::Admin), $at));
            $team = new Team(new TeamId($teamId), $organization, 'Shop', new TeamSlug('shop'), new UserId(self::LENA), Job::Developer, $at);
            $team->addMember(new UserId(self::MIA), TeamRole::Member, Job::Tester, $at);
            new DoctrineTeams($entityManager)->add($team);
            new DoctrineOrgSettingsStore($entityManager)->add(new OrgSettings($organization, new UserId(self::SARA), $at));
            $invitations = new DoctrineInvitations($entityManager);
            $invitations->add(Invitation::issue($invitations->nextIdentity(), $organization, 'omar@'.$slug.'.example', new OrgRoles(), new TeamId($teamId), TeamRole::Viewer, new UserId(self::SARA), $at));
        });
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}

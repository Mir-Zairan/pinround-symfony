<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Application\NotInTheOrganization;
use App\Organization\Application\OrganizationPeopleQueries;
use App\Organization\Application\OrganizationPerson;
use App\Organization\Application\PersonInTeam;
use App\Organization\Domain\TeamSlug;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalOrganizationPeopleQueries as the container wires it, as pinround_app on committed rows of two organizations.
 * Neither the user ids, the order the memberships were added in, nor byte order of the names (lowercase "lena" sorts
 * after every capital) gives the name order, and the two Mia Lunds tie on name, so only the id breaks the tie.
 */
final class DbalOrganizationPeopleQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000014001';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000014002';
    // Shop has the lower id, so a person's teams in id order would put Shop before Payments.
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-000000014011';
    private const string PAYMENTS_TEAM = '0197a3c0-0000-7000-8000-000000014012';
    private const string CHECKOUT_TEAM = '0197a3c0-0000-7000-8000-000000014013';
    private const string SARA = '0197a3c0-0000-7000-8000-000000014021';
    private const string RAJ = '0197a3c0-0000-7000-8000-000000014022';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000014023';
    private const string MIA_LOWER_ID = '0197a3c0-0000-7000-8000-000000014024';
    private const string MIA_HIGHER_ID = '0197a3c0-0000-7000-8000-000000014025';
    private const string LENA = '0197a3c0-0000-7000-8000-000000014026';
    private const string ANNA = '0197a3c0-0000-7000-8000-000000014027';
    private const array USERS = [
        self::SARA => 'Sara Holm',
        self::RAJ => 'Raj Patel',
        self::OMAR => 'Omar Haddad',
        self::MIA_LOWER_ID => 'Mia Lund',
        self::MIA_HIGHER_ID => 'Mia Lund',
        self::LENA => 'lena Berg',
        self::ANNA => 'Anna Baker',
    ];
    /** Acme Foods' people in the order the page must give: name ignoring case, then id. */
    private const array ACME_IN_NAME_ORDER = [
        [self::LENA, 'lena Berg'],
        [self::MIA_LOWER_ID, 'Mia Lund'],
        [self::MIA_HIGHER_ID, 'Mia Lund'],
        [self::OMAR, 'Omar Haddad'],
        [self::RAJ, 'Raj Patel'],
        [self::SARA, 'Sara Holm'],
    ];

    protected function setUp(): void
    {
        $this->removeRows();
        foreach (self::USERS as $userId => $name) {
            $this->migrator()->executeStatement(
                "INSERT INTO idn_user (id, email, name, status, locale, time_zone, created_at, session_stamp) VALUES (?, ?, ?, 'active', 'en', 'UTC', '2027-03-15 10:00:00+00', 'a-stamp')",
                [$userId, 'person.'.substr($userId, -4).'@acme-foods.example', $name],
            );
        }
        // Beta Bakery's Anna would come first and Lena's Checkout team would join her teams, were either to leak.
        $this->transactions()->committedAs(self::BETA_BAKERY, function (): void {
            $this->addMembership(self::BETA_BAKERY, self::ANNA, '{admin}');
            $this->addMembership(self::BETA_BAKERY, self::LENA, '{manager}');
            $this->addTeam(self::BETA_BAKERY, self::CHECKOUT_TEAM, 'Checkout', self::LENA);
            $this->addTeamMember(self::BETA_BAKERY, self::CHECKOUT_TEAM, self::LENA, 'lead', 'developer');
        });
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addMembership(self::ACME_FOODS, self::SARA, '{admin}');
            $this->addMembership(self::ACME_FOODS, self::MIA_HIGHER_ID, '{}');
            $this->addMembership(self::ACME_FOODS, self::OMAR, '{}');
            $this->addMembership(self::ACME_FOODS, self::LENA, '{}');
            $this->addMembership(self::ACME_FOODS, self::RAJ, '{manager,admin}');
            $this->addMembership(self::ACME_FOODS, self::MIA_LOWER_ID, '{}');
            $this->addTeam(self::ACME_FOODS, self::SHOP_TEAM, 'Shop', self::LENA);
            $this->addTeam(self::ACME_FOODS, self::PAYMENTS_TEAM, 'payments', self::RAJ);
            $this->addTeamMember(self::ACME_FOODS, self::SHOP_TEAM, self::LENA, 'lead', 'developer');
            $this->addTeamMember(self::ACME_FOODS, self::SHOP_TEAM, self::OMAR, 'member', 'tester');
            $this->addTeamMember(self::ACME_FOODS, self::SHOP_TEAM, self::RAJ, 'viewer', 'other');
            $this->addTeamMember(self::ACME_FOODS, self::PAYMENTS_TEAM, self::RAJ, 'lead', 'developer');
        });
    }

    protected function tearDown(): void
    {
        $this->removeRows();
        parent::tearDown();
    }

    #[Test]
    public function aPageHoldsOnlyTheOrganizationsPeopleByNameIgnoringCaseThenById(): void
    {
        self::assertSame(self::ACME_IN_NAME_ORDER, self::namesOf($this->pageAs(self::ACME_FOODS, 100)));
        self::assertSame([[self::ANNA, 'Anna Baker'], [self::LENA, 'lena Berg']], self::namesOf($this->pageAs(self::BETA_BAKERY, 100)));
    }

    #[Test]
    public function aPersonInTwoTeamsHasBothWithTheirRolesAndJobsByTeamNameAndOrgRolesOfThisOrganizationOnly(): void
    {
        $people = $this->pageAs(self::ACME_FOODS, 100);

        self::assertNotEmpty($people);
        $described = [];
        foreach ($people as $person) {
            $described[$person->userId->toString()] = self::describe($person);
        }
        self::assertSame(['isAdmin' => true, 'isManager' => true, 'teams' => [['payments', 'lead', 'developer'], ['Shop', 'viewer', 'other']]], $described[self::RAJ]);
        self::assertSame(['isAdmin' => false, 'isManager' => false, 'teams' => [['Shop', 'lead', 'developer']]], $described[self::LENA]);
        self::assertSame(['isAdmin' => false, 'isManager' => false, 'teams' => [['Shop', 'member', 'tester']]], $described[self::OMAR]);
        self::assertSame(['isAdmin' => true, 'isManager' => false, 'teams' => []], $described[self::SARA]);
        self::assertSame(['isAdmin' => false, 'isManager' => false, 'teams' => []], $described[self::MIA_LOWER_ID]);

        $lenaInBeta = array_values(array_filter($this->pageAs(self::BETA_BAKERY, 100), static fn (OrganizationPerson $person): bool => self::LENA === $person->userId->toString()));
        self::assertCount(1, $lenaInBeta);
        self::assertSame(['isAdmin' => false, 'isManager' => true, 'teams' => [['Checkout', 'lead', 'developer']]], self::describe($lenaInBeta[0]));
    }

    /**
     * Two at a time, so the first page ends between the two Mia Lunds and only the id says where the next one starts.
     */
    #[Test]
    public function eachPageStartsAfterTheGivenPersonWithNoGapAndNoRepeat(): void
    {
        $firstPage = self::namesOf($this->pageAs(self::ACME_FOODS, 2));
        $secondPage = self::namesOf($this->pageAs(self::ACME_FOODS, 2, self::MIA_LOWER_ID));
        $thirdPage = self::namesOf($this->pageAs(self::ACME_FOODS, 2, self::OMAR));

        self::assertSame(\array_slice(self::ACME_IN_NAME_ORDER, 0, 2), $firstPage);
        self::assertSame(\array_slice(self::ACME_IN_NAME_ORDER, 2, 2), $secondPage);
        self::assertSame(\array_slice(self::ACME_IN_NAME_ORDER, 4, 2), $thirdPage);
        self::assertSame([], $this->pageAs(self::ACME_FOODS, 2, self::SARA));
    }

    #[Test]
    public function aPageAfterSomeoneOutsideTheOrganizationIsRefused(): void
    {
        $this->expectException(NotInTheOrganization::class);

        $this->pageAs(self::ACME_FOODS, 2, self::ANNA);
    }

    #[Test]
    public function underAnotherTenantTheOrganizationHasNobody(): void
    {
        $people = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->queries()->pageOf(new OrganizationId(self::ACME_FOODS), 100));

        self::assertSame([], $people);
    }

    #[Test]
    public function aLimitOutsideOneToTheMaximumIsRefused(): void
    {
        foreach ([0, OrganizationPeopleQueries::MAX_LIMIT + 1] as $limit) {
            try {
                $this->pageAs(self::ACME_FOODS, $limit);
                self::fail(\sprintf('A limit of %d was taken.', $limit));
            } catch (\InvalidArgumentException $refused) {
                self::assertSame(\sprintf('A page of people holds 1 to 100, not %d.', $limit), $refused->getMessage());
            }
        }
    }

    /**
     * @return list<OrganizationPerson>
     */
    private function pageAs(string $organizationId, int $limit, ?string $after = null): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->queries()->pageOf(new OrganizationId($organizationId), $limit, null === $after ? null : new UserId($after)));
    }

    /**
     * @param list<OrganizationPerson> $people
     *
     * @return list<array{string, string}>
     */
    private static function namesOf(array $people): array
    {
        return array_map(static fn (OrganizationPerson $person): array => [$person->userId->toString(), $person->displayName], $people);
    }

    /**
     * @return array{isAdmin: bool, isManager: bool, teams: list<array{string, string, string}>}
     */
    private static function describe(OrganizationPerson $person): array
    {
        return [
            'isAdmin' => $person->isAdmin,
            'isManager' => $person->isManager,
            'teams' => array_map(static fn (PersonInTeam $team): array => [$team->teamName, $team->teamRole->value, $team->job->value], $person->teams),
        ];
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
            "INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, ?, ?, ?, ?, '2027-03-15 10:00:00+00')",
            [$organizationId, $teamId, $name, TeamSlug::fromName($name)->toString(), $leadUserId],
        );
    }

    private function addTeamMember(string $organizationId, string $teamId, string $userId, string $teamRole, string $job): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES (?, ?, ?, ?, ?, '2027-03-15 10:00:00+00')",
            [$organizationId, $teamId, $userId, $teamRole, $job],
        );
    }

    private function removeRows(): void
    {
        new OrganizationRows($this->migrator(), $this->tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        foreach (array_keys(self::USERS) as $userId) {
            $this->migrator()->executeStatement('DELETE FROM idn_user WHERE id = ?', [$userId]);
        }
    }

    private function queries(): OrganizationPeopleQueries
    {
        return self::getContainer()->get(OrganizationPeopleQueries::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
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

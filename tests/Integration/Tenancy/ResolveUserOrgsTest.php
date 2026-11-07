<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tenancy;

use App\Organization\Domain\Membership;
use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use App\Organization\Infrastructure\Doctrine\DoctrineMemberships;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * resolve_user_orgs on real org_membership rows, called as pinround_app before any tenant is known, as the org
 * picker does after login (data-model.md › Lookups before the tenant is known).
 */
final class ResolveUserOrgsTest extends KernelTestCase
{
    // Ids in sort order, so the resolver's ORDER BY organization_id is visible.
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000007a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000007b1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000752';
    private const string MIA = '0197a3c0-0000-7000-8000-000000000754';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000753';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $joinedAt = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');
        $transactions = new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
        $memberships = new DoctrineMemberships(self::getContainer()->get('doctrine.orm.default_entity_manager'));
        $transactions->committedAs(self::BETA_BAKERY, static function () use ($memberships, $joinedAt): void {
            $memberships->add(new Membership(new OrganizationId(self::BETA_BAKERY), new UserId(self::MIA), new OrgRoles(OrgRole::Admin), $joinedAt));
            $memberships->add(new Membership(new OrganizationId(self::BETA_BAKERY), new UserId(self::LENA), new OrgRoles(), $joinedAt));
        });
        $transactions->committedAs(self::ACME_FOODS, static fn () => $memberships->add(
            new Membership(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), new OrgRoles(OrgRole::Admin), $joinedAt),
        ));
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
    }

    #[Test]
    public function aUserGetsOnlyTheirOwnOrganizationsNeverAnotherMembersOnes(): void
    {
        self::assertSame([self::BETA_BAKERY], $this->organizationsOf(self::MIA));
    }

    #[Test]
    public function aUserWithoutMembershipsGetsNoRow(): void
    {
        self::assertSame([], $this->organizationsOf(self::OMAR));
    }

    #[Test]
    public function itAnswersTheSameWhileAnotherOrganizationIsTheTenant(): void
    {
        self::getContainer()->get(TenantContext::class)->set(new OrganizationId(self::BETA_BAKERY));

        $organizations = $this->connection()->transactional(static fn (Connection $connection): array => $connection->fetchFirstColumn(
            'SELECT organization_id FROM resolve_user_orgs(?)',
            [self::LENA],
        ));

        self::getContainer()->get(TenantContext::class)->clear();
        self::assertSame([self::ACME_FOODS, self::BETA_BAKERY], $organizations);
    }

    #[Test]
    public function theAppRoleCannotReadTheMembershipsWithoutATenant(): void
    {
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM org_membership'));
    }

    /**
     * @return list<mixed>
     */
    private function organizationsOf(string $userId): array
    {
        return $this->connection()->fetchFirstColumn('SELECT organization_id FROM resolve_user_orgs(?)', [$userId]);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}

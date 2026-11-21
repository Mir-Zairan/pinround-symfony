<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Identity\Api\MemberOrganization;
use App\Identity\Api\MemberOrganizations;
use App\Organization\Domain\Organization;
use App\Organization\Infrastructure\Doctrine\DoctrineOrganizations;
use App\Organization\Infrastructure\Doctrine\NamedMemberOrganizations;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NamedMemberOrganizations, as Identity gets it from the container, on the real org_ tables as pinround_app. Omar is a
 * member of Acme Foods and Beta Bakery and left Cedar Cafe; Delta Dairy is Mia's alone. Beta Bakery's id sorts before
 * Acme Foods' and its membership is added after, so neither the names nor the insert order give the id order.
 */
final class NamedMemberOrganizationsTest extends KernelTestCase
{
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000001f0a1';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000001f0a2';
    private const string CEDAR_CAFE = '0197a3c0-0000-7000-8000-00000001f0a3';
    private const string DELTA_DAIRY = '0197a3c0-0000-7000-8000-00000001f0a4';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000001f0c1';
    private const string MIA = '0197a3c0-0000-7000-8000-00000001f0c2';
    private const string RAJ = '0197a3c0-0000-7000-8000-00000001f0c3';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::BETA_BAKERY, self::ACME_FOODS, self::CEDAR_CAFE, self::DELTA_DAIRY);
        foreach ([self::ACME_FOODS => 'Acme Foods', self::BETA_BAKERY => 'Beta Bakery', self::CEDAR_CAFE => 'Cedar Cafe', self::DELTA_DAIRY => 'Delta Dairy'] as $organizationId => $name) {
            $this->transactions()->committedAs($organizationId, fn () => $this->organizations()->add(
                new Organization(new OrganizationId($organizationId), $name, 'member-orgs-'.$organizationId, new \DateTimeImmutable('2027-03-15 10:00:00 UTC')),
            ));
        }
        $this->addMembership(self::ACME_FOODS, self::OMAR);
        $this->addMembership(self::BETA_BAKERY, self::OMAR);
        $this->addMembership(self::CEDAR_CAFE, self::OMAR);
        $this->addMembership(self::DELTA_DAIRY, self::MIA);
        $this->transactions()->committedAs(self::CEDAR_CAFE, fn (): int|string => $this->connection()->executeStatement(
            'DELETE FROM org_membership WHERE organization_id = ? AND user_id = ?',
            [self::CEDAR_CAFE, self::OMAR],
        ));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->rows()->removeAllOf(self::BETA_BAKERY, self::ACME_FOODS, self::CEDAR_CAFE, self::DELTA_DAIRY);
        parent::tearDown();
    }

    #[Test]
    public function aPersonGetsTheNamedOrganizationsTheyAreAMemberOfNowInIdOrder(): void
    {
        self::assertSame(
            [[self::BETA_BAKERY, 'Beta Bakery'], [self::ACME_FOODS, 'Acme Foods']],
            self::idsAndNames($this->memberOrganizations()->of(new UserId(self::OMAR))),
        );
        self::assertSame([[self::DELTA_DAIRY, 'Delta Dairy']], self::idsAndNames($this->memberOrganizations()->of(new UserId(self::MIA))));
    }

    #[Test]
    public function aPersonInNoOrganizationGetsAnEmptyList(): void
    {
        self::assertSame([], $this->memberOrganizations()->of(new UserId(self::RAJ)));
    }

    #[Test]
    public function theTenantIsPutBackAndNoTransactionIsLeftOpen(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::DELTA_DAIRY));

        self::assertCount(2, $this->memberOrganizations()->of(new UserId(self::OMAR)));

        self::assertSame(self::DELTA_DAIRY, $this->tenantContext()->get()?->toString());
        self::assertFalse($this->connection()->isTransactionActive());
    }

    #[Test]
    public function anOpenTransactionIsRefusedAndTheTenantStays(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::DELTA_DAIRY));
        $this->connection()->beginTransaction();
        try {
            $this->memberOrganizations()->of(new UserId(self::OMAR));
            self::fail('The organizations were read inside an open transaction.');
        } catch (TenantSwitchRefused) {
            self::assertSame(self::DELTA_DAIRY, $this->tenantContext()->get()?->toString());
        } finally {
            $this->connection()->rollBack();
        }
    }

    /**
     * @param list<MemberOrganization> $memberOrganizations
     *
     * @return list<array{string, string}>
     */
    private static function idsAndNames(array $memberOrganizations): array
    {
        return array_map(static fn (MemberOrganization $organization): array => [$organization->id->toString(), $organization->name], $memberOrganizations);
    }

    private function addMembership(string $organizationId, string $userId): void
    {
        $this->transactions()->committedAs($organizationId, fn (): int|string => $this->connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2027-03-15 10:00:00+00')",
            [$organizationId, $userId],
        ));
    }

    private function memberOrganizations(): MemberOrganizations
    {
        $memberOrganizations = self::getContainer()->get('test.identity_member_organizations');
        self::assertInstanceOf(NamedMemberOrganizations::class, $memberOrganizations);

        return $memberOrganizations;
    }

    private function organizations(): DoctrineOrganizations
    {
        return new DoctrineOrganizations($this->entityManager());
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

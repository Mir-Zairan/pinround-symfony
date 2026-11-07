<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Infrastructure\Doctrine\DbalMembershipQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalMembershipQueries as pinround_app on committed org_membership rows, with no tenant set, as before a request has
 * one.
 */
final class DbalMembershipQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000004a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000004b1';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000004c1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000004c2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000004c3';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        // Beta Bakery first, so the result's id order differs from the order the rows were added in.
        $this->addMembership(self::BETA_BAKERY, self::LENA);
        $this->addMembership(self::ACME_FOODS, self::LENA);
        $this->addMembership(self::BETA_BAKERY, self::OMAR);
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

    /**
     * @return list<string>
     */
    private function organizationsOf(string $userId): array
    {
        $queries = new DbalMembershipQueries($this->connection());

        return array_map(static fn (OrganizationId $organizationId): string => $organizationId->toString(), $queries->organizationsOf(new UserId($userId)));
    }

    private function addMembership(string $organizationId, string $userId): void
    {
        $this->transactions()->committedAs($organizationId, fn (): int|string => $this->connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2027-03-15 10:00:00+00')",
            [$organizationId, $userId],
        ));
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

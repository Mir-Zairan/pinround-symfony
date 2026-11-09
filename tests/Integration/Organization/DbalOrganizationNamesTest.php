<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Domain\Organization;
use App\Organization\Infrastructure\Doctrine\DbalOrganizationNames;
use App\Organization\Infrastructure\Doctrine\DoctrineOrganizations;
use App\Organization\Infrastructure\Doctrine\TenantSwitchingReads;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalOrganizationNames on the real org_organization table as pinround_app, under row-level security.
 */
final class DbalOrganizationNamesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000003a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000003b1';
    private const string NO_ROW = '0197a3c0-0000-7000-8000-0000000003c1';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        foreach ([self::ACME_FOODS => 'Acme Foods', self::BETA_BAKERY => 'Beta Bakery'] as $organizationId => $name) {
            $this->transactions()->committedAs($organizationId, fn () => $this->organizations()->add(
                new Organization(new OrganizationId($organizationId), $name, 'names-'.$organizationId, new \DateTimeImmutable('2027-03-15 10:00:00 UTC')),
            ));
        }
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function eachNameIsReadUnderItsOwnOrganizationAndTheTenantIsPutBack(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::BETA_BAKERY));

        $names = $this->names()->namesOf([new OrganizationId(self::BETA_BAKERY), new OrganizationId(self::NO_ROW), new OrganizationId(self::ACME_FOODS)]);

        self::assertSame([self::BETA_BAKERY => 'Beta Bakery', self::ACME_FOODS => 'Acme Foods'], $names);
        self::assertSame(self::BETA_BAKERY, $this->tenantContext()->get()?->toString());
        self::assertFalse($this->connection()->isTransactionActive());
    }

    #[Test]
    public function withNoTenantBeforeNoneIsLeftAfter(): void
    {
        self::assertSame([self::ACME_FOODS => 'Acme Foods'], $this->names()->namesOf([new OrganizationId(self::ACME_FOODS)]));
        self::assertNull($this->tenantContext()->get());
    }

    #[Test]
    public function anOpenTransactionIsRefused(): void
    {
        $this->connection()->beginTransaction();
        try {
            $this->expectException(TenantSwitchRefused::class);
            $this->names()->namesOf([new OrganizationId(self::ACME_FOODS)]);
        } finally {
            $this->connection()->rollBack();
        }
    }

    #[Test]
    public function theTenantsNameIsNotReadWithoutATenantOrOutsideItsTransaction(): void
    {
        self::assertNull($this->names()->nameOfTenant());
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        self::assertNull($this->names()->nameOfTenant());
    }

    #[Test]
    public function theTenantsNameIsReadInsideItsTransaction(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $name = $this->connection()->transactional(fn (): ?string => $this->names()->nameOfTenant());

        self::assertSame('Acme Foods', $name);
    }

    private function names(): DbalOrganizationNames
    {
        return new DbalOrganizationNames($this->connection(), $this->tenantContext(), new TenantSwitchingReads($this->connection(), $this->entityManager(), $this->tenantContext()));
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

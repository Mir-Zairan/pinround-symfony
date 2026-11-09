<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Domain\Organization;
use App\Organization\Infrastructure\Doctrine\DoctrineOrganizations;
use App\Organization\Infrastructure\Doctrine\DoctrineOrganizationTransactions;
use App\Organization\Infrastructure\Doctrine\TenantSwitchingReads;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineOrganizationTransactions on the real org_organization table as pinround_app, under row-level security.
 */
final class DoctrineOrganizationTransactionsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000004a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000004b1';
    private const string NEW_ORGANIZATION = '0197a3c0-0000-7000-8000-0000000004c1';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY, self::NEW_ORGANIZATION);
        foreach ([self::ACME_FOODS => 'Acme Foods', self::BETA_BAKERY => 'Beta Bakery'] as $organizationId => $name) {
            new TenantTransactions($this->entityManager(), $this->tenantContext())->committedAs($organizationId, fn () => $this->organizations()->add(
                new Organization(new OrganizationId($organizationId), $name, 'transactions-'.$organizationId, new \DateTimeImmutable('2027-03-15 10:00:00 UTC')),
            ));
        }
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY, self::NEW_ORGANIZATION);
        parent::tearDown();
    }

    #[Test]
    public function theWriteRunsUnderTheOrganizationCommitsAndTheTenantIsPutBack(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::BETA_BAKERY));

        $visibleNames = $this->transactions()->runAs(new OrganizationId(self::ACME_FOODS), fn (): array => $this->connection()->fetchFirstColumn('SELECT name FROM org_organization'));
        $this->transactions()->runAs(new OrganizationId(self::NEW_ORGANIZATION), fn () => $this->organizations()->add($this->newOrganization()));

        self::assertSame(['Acme Foods'], $visibleNames);
        self::assertSame(self::BETA_BAKERY, $this->tenantContext()->get()?->toString());
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame(1, $this->organizationRowCount(self::NEW_ORGANIZATION));
    }

    #[Test]
    public function aFailedWriteIsRolledBackAndLeavesNoTenantWhenThereWasNone(): void
    {
        $thrown = $this->thrownBy(fn () => $this->transactions()->runAs(new OrganizationId(self::NEW_ORGANIZATION), function (): void {
            $this->organizations()->add($this->newOrganization());
            $this->entityManager()->flush();

            throw new \DomainException('refused');
        }));

        self::assertInstanceOf(\DomainException::class, $thrown);
        self::assertNull($this->tenantContext()->get());
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame(0, $this->organizationRowCount(self::NEW_ORGANIZATION));
    }

    #[Test]
    public function anOpenTransactionIsRefusedBeforeTheWriteRuns(): void
    {
        /** @var \ArrayObject<int, true> $runs */
        $runs = new \ArrayObject();
        $this->connection()->beginTransaction();
        try {
            $thrown = $this->thrownBy(fn () => $this->transactions()->runAs(new OrganizationId(self::ACME_FOODS), static function () use ($runs): void {
                $runs->append(true);
            }));
        } finally {
            $this->connection()->rollBack();
        }

        self::assertInstanceOf(TenantSwitchRefused::class, $thrown);
        self::assertCount(0, $runs, 'the write ran');
        self::assertNull($this->tenantContext()->get());
    }

    private function thrownBy(\Closure $call): ?\Throwable
    {
        try {
            $call();
        } catch (\Throwable $thrown) {
            return $thrown;
        }

        return null;
    }

    private function newOrganization(): Organization
    {
        return new Organization(new OrganizationId(self::NEW_ORGANIZATION), 'Acme Foods North', 'transactions-'.self::NEW_ORGANIZATION, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
    }

    /**
     * Through the migrator, which FORCE ROW LEVEL SECURITY binds too, so it counts under the organization as tenant.
     */
    private function organizationRowCount(string $organizationId): int
    {
        $this->tenantContext()->reset();
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $count = self::getContainer()->get('doctrine.dbal.migrator_connection')->transactional(
                static fn (Connection $migrator): mixed => $migrator->fetchOne('SELECT count(*) FROM org_organization WHERE organization_id = ?', [$organizationId]),
            );
        } finally {
            $this->tenantContext()->reset();
        }

        return \is_int($count) ? $count : -1;
    }

    private function transactions(): DoctrineOrganizationTransactions
    {
        return new DoctrineOrganizationTransactions(new TenantSwitchingReads($this->connection(), $this->entityManager(), $this->tenantContext()), $this->entityManager(), $this->tenantContext());
    }

    private function organizations(): DoctrineOrganizations
    {
        return new DoctrineOrganizations($this->entityManager());
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
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

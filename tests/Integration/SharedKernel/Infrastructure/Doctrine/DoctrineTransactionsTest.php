<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Doctrine\DoctrineTransactions;
use App\SharedKernel\Infrastructure\Doctrine\TenantSetting;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineTransactions on a real tenant table (wf_step) as pinround_app, under row-level security. The rows are
 * written and counted through plain DBAL, so the test depends on no module.
 */
final class DoctrineTransactionsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000012a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000012a2';
    private const string DESIGN_CHECK = '0197a3c0-0000-7000-8000-0000000012d1';
    private const string BAKERY_CHECK = '0197a3c0-0000-7000-8000-0000000012d2';

    protected function setUp(): void
    {
        $this->removeStepsOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->removeStepsOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function theWriteRunsUnderTheRequestsTenantAndIsCommitted(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::BETA_BAKERY));
        $this->transactions()->run(fn () => $this->insertCompanyStep(self::BETA_BAKERY, self::BAKERY_CHECK, 'Taste check'));
        $this->tenantContext()->reset();
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        [$tenantSetting, $visibleNames] = $this->transactions()->run(fn (): array => [
            $this->connection()->fetchOne('SELECT current_setting(?, true)', [TenantSetting::NAME]),
            $this->connection()->fetchFirstColumn('SELECT name FROM wf_step'),
        ]);
        $this->transactions()->run(fn () => $this->insertCompanyStep(self::ACME_FOODS, self::DESIGN_CHECK, 'Design check'));

        self::assertSame(self::ACME_FOODS, $tenantSetting);
        self::assertSame([], $visibleNames, 'a step of another organization is visible');
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame(1, $this->stepRowCount(self::ACME_FOODS, self::DESIGN_CHECK));
    }

    #[Test]
    public function aFailedWriteIsRolledBackTheEntityManagerClosedAndTheExceptionRethrown(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $thrown = $this->thrownBy(fn () => $this->transactions()->run(function (): void {
            $this->insertCompanyStep(self::ACME_FOODS, self::DESIGN_CHECK, 'Design check');

            throw new \DomainException('refused');
        }));

        self::assertInstanceOf(\DomainException::class, $thrown);
        self::assertSame('refused', $thrown->getMessage());
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertFalse($this->entityManager()->isOpen());
        self::assertSame(0, $this->stepRowCount(self::ACME_FOODS, self::DESIGN_CHECK));
    }

    #[Test]
    public function withoutATenantTheWriteDoesNotRun(): void
    {
        /** @var \ArrayObject<int, true> $runs */
        $runs = new \ArrayObject();

        $thrown = $this->thrownBy(fn () => $this->transactions()->run(static function () use ($runs): void {
            $runs->append(true);
        }));

        self::assertInstanceOf(\LogicException::class, $thrown);
        self::assertCount(0, $runs, 'the write ran');
        self::assertFalse($this->connection()->isTransactionActive());
    }

    #[Test]
    public function insideAnOpenTransactionTheWriteDoesNotRun(): void
    {
        /** @var \ArrayObject<int, true> $runs */
        $runs = new \ArrayObject();
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        $this->connection()->beginTransaction();
        try {
            $thrown = $this->thrownBy(fn () => $this->transactions()->run(static function () use ($runs): void {
                $runs->append(true);
            }));
            $nestingLevelAfter = $this->connection()->getTransactionNestingLevel();
        } finally {
            $this->connection()->rollBack();
        }

        self::assertInstanceOf(\LogicException::class, $thrown);
        self::assertCount(0, $runs, 'the write ran');
        self::assertSame(1, $nestingLevelAfter);
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

    /**
     * A company step: no team and no core code.
     */
    private function insertCompanyStep(string $organizationId, string $stepId, string $name): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position, archived_at) VALUES (?, ?, NULL, ?, 'check', NULL, 'company', 0, NULL)",
            [$organizationId, $stepId, $name],
        );
    }

    /**
     * Through the migrator, which FORCE ROW LEVEL SECURITY binds too, so it counts under the organization as tenant.
     */
    private function stepRowCount(string $organizationId, string $stepId): int
    {
        $this->tenantContext()->reset();
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $count = $this->migrator()->transactional(
                static fn (Connection $migrator): mixed => $migrator->fetchOne('SELECT count(*) FROM wf_step WHERE id = ?', [$stepId]),
            );
        } finally {
            $this->tenantContext()->reset();
        }

        return \is_int($count) ? $count : -1;
    }

    /**
     * Through the migrator, which owns wf_step and so may delete from it (the app may not), under each organization as
     * tenant.
     */
    private function removeStepsOf(string ...$organizationIds): void
    {
        foreach ($organizationIds as $organizationId) {
            $this->tenantContext()->set(new OrganizationId($organizationId));
            try {
                $this->migrator()->transactional(static function (Connection $migrator) use ($organizationId): void {
                    $migrator->executeStatement('DELETE FROM wf_step WHERE organization_id = ?', [$organizationId]);
                });
            } finally {
                $this->tenantContext()->reset();
            }
        }
    }

    private function transactions(): DoctrineTransactions
    {
        return new DoctrineTransactions($this->entityManager(), $this->tenantContext(), self::getContainer()->get('doctrine'));
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
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

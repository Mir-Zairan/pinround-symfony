<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Infrastructure\Doctrine\DoctrineTransactions;
use App\SharedKernel\Infrastructure\Doctrine\TenantSetting;
use App\Tests\Integration\Ticketing\ProjectRows;
use App\Ticketing\Domain\Project;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Infrastructure\Doctrine\DoctrineProjects;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineTransactions on the real tkt_project table as pinround_app, under row-level security. The writes go through
 * the entity manager, so the flush at commit is covered too.
 */
final class DoctrineTransactionsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000012a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000012a2';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000012c1';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000012d1';
    private const string BAKERY_PROJECT = '0197a3c0-0000-7000-8000-0000000012d2';

    protected function setUp(): void
    {
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->tenantContext()->reset();
        $this->rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function theWriteRunsUnderTheRequestsTenantAndIsCommitted(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::BETA_BAKERY));
        $this->transactions()->run(fn () => $this->projects()->add($this->project(self::BAKERY_PROJECT, self::BETA_BAKERY, 'BREAD')));
        $this->entityManager()->clear();
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        [$tenantSetting, $visibleKeys] = $this->transactions()->run(fn (): array => [
            $this->connection()->fetchOne('SELECT current_setting(?, true)', [TenantSetting::NAME]),
            $this->connection()->fetchFirstColumn('SELECT key FROM tkt_project'),
        ]);
        $this->transactions()->run(fn () => $this->projects()->add($this->project(self::SHOP_PROJECT, self::ACME_FOODS, 'SHOP')));

        self::assertSame(self::ACME_FOODS, $tenantSetting);
        self::assertSame([], $visibleKeys, 'a project of another organization is visible');
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertSame(1, $this->projectRowCount(self::ACME_FOODS, self::SHOP_PROJECT));
    }

    #[Test]
    public function aFailedWriteIsRolledBackTheEntityManagerClosedAndTheExceptionRethrown(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $thrown = $this->thrownBy(fn () => $this->transactions()->run(function (): void {
            $this->projects()->add($this->project(self::SHOP_PROJECT, self::ACME_FOODS, 'SHOP'));

            throw new \DomainException('refused');
        }));

        self::assertInstanceOf(\DomainException::class, $thrown);
        self::assertSame('refused', $thrown->getMessage());
        self::assertFalse($this->connection()->isTransactionActive());
        self::assertFalse($this->entityManager()->isOpen());
        self::assertSame(0, $this->projectRowCount(self::ACME_FOODS, self::SHOP_PROJECT));
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

    private function project(string $projectId, string $organizationId, string $key): Project
    {
        return new Project(new ProjectId($projectId), new OrganizationId($organizationId), new TeamId(self::SHOP_TEAM), new ProjectKey($key), 'Project '.$key);
    }

    /**
     * Through the migrator, which FORCE ROW LEVEL SECURITY binds too, so it counts under the organization as tenant.
     */
    private function projectRowCount(string $organizationId, string $projectId): int
    {
        $this->tenantContext()->reset();
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $count = self::getContainer()->get('doctrine.dbal.migrator_connection')->transactional(
                static fn (Connection $migrator): mixed => $migrator->fetchOne('SELECT count(*) FROM tkt_project WHERE id = ?', [$projectId]),
            );
        } finally {
            $this->tenantContext()->reset();
        }

        return \is_int($count) ? $count : -1;
    }

    private function transactions(): DoctrineTransactions
    {
        return new DoctrineTransactions($this->entityManager(), $this->tenantContext());
    }

    private function projects(): DoctrineProjects
    {
        return new DoctrineProjects($this->entityManager());
    }

    private function rows(): ProjectRows
    {
        return new ProjectRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
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

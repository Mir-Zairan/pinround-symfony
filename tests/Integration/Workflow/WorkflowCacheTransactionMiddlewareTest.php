<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Infrastructure\Cache\WorkflowCache;
use App\Workflow\Infrastructure\Cache\WorkflowCacheTransactionMiddleware;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\PgSQL\Driver as PgSQLDriver;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * A COMMIT that fails at the driver: WorkflowCacheTransactionMiddleware must end the cache's transaction without
 * invalidating the change's tags, so neither the tags nor the tenant of that transaction outlive it.
 */
final class WorkflowCacheTransactionMiddlewareTest extends KernelTestCase
{
    private const string ORG_ACME = '0197a3c0-0000-7000-8000-000000002200';
    private const string TEAM_SHOP = '0197a3c0-0000-7000-8000-000000002201';

    private WorkflowCache $workflowCache;

    private Connection $connection;

    /** Set to make the next COMMIT fail at the driver after rolling back there. */
    private bool $isNextCommitFailing = false;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $tenantContext = $container->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ORG_ACME));
        $this->workflowCache = new WorkflowCache(new TagAwareAdapter(new ArrayAdapter()), new NullLogger());
        $middleware = new WorkflowCacheTransactionMiddleware($this->workflowCache, $tenantContext);
        $this->connection = new Connection(
            $container->get('doctrine.dbal.default_connection')->getParams(),
            $middleware->wrap($this->pgSqlDriverWhoseCommitCanFail()),
        );
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        self::getContainer()->get(TenantContext::class)->clear();
        parent::tearDown();
    }

    #[Test]
    public function aFailedCommitInvalidatesNoneOfTheTagsItsChangeQueued(): void
    {
        $this->rememberInATransaction('cached before the change');

        $this->connection->beginTransaction();
        $this->workflowCache->invalidateAfterCommit(new OrganizationId(self::ORG_ACME), new TeamId(self::TEAM_SHOP));
        $this->commitFailing();

        self::assertSame('cached before the change', $this->rememberInATransaction('loaded after the failed commit'));
    }

    #[Test]
    public function aFailedCommitLeavesNoTenantBehindAndTheNextTransactionCachesAgain(): void
    {
        $this->rememberInATransaction('cached before the failed commit');

        $this->connection->beginTransaction();
        $this->commitFailing();

        self::assertSame('loaded with no transaction open', $this->rememberTheSteps('loaded with no transaction open'));
        self::assertSame('cached before the failed commit', $this->rememberInATransaction('loaded in the next transaction'));
    }

    private function rememberInATransaction(string $loaded): string
    {
        $this->connection->beginTransaction();
        $remembered = $this->rememberTheSteps($loaded);
        $this->connection->commit();

        return $remembered;
    }

    private function rememberTheSteps(string $loaded): string
    {
        return $this->workflowCache->remember(new TeamId(self::TEAM_SHOP), 'steps', static fn (): string => $loaded);
    }

    private function commitFailing(): void
    {
        $this->isNextCommitFailing = true;
        try {
            $this->connection->commit();
            self::fail('The commit did not fail.');
        } catch (DriverException $failure) {
            self::assertSame('COMMIT failed on purpose', $failure->getPrevious()?->getMessage());
        }
        self::assertFalse($this->connection->isTransactionActive());
    }

    private function pgSqlDriverWhoseCommitCanFail(): Driver
    {
        $isNextCommitFailing = function (): bool {
            $isFailing = $this->isNextCommitFailing;
            $this->isNextCommitFailing = false;

            return $isFailing;
        };

        return new class(new PgSQLDriver(), $isNextCommitFailing) extends AbstractDriverMiddleware {
            /**
             * @param \Closure(): bool $isNextCommitFailing
             */
            public function __construct(Driver $driver, private readonly \Closure $isNextCommitFailing)
            {
                parent::__construct($driver);
            }

            public function connect(
                #[\SensitiveParameter]
                array $params,
            ): DriverConnection {
                return new class(parent::connect($params), $this->isNextCommitFailing) extends AbstractConnectionMiddleware {
                    /**
                     * @param \Closure(): bool $isNextCommitFailing
                     */
                    public function __construct(DriverConnection $connection, private readonly \Closure $isNextCommitFailing)
                    {
                        parent::__construct($connection);
                    }

                    public function commit(): void
                    {
                        if (!($this->isNextCommitFailing)()) {
                            parent::commit();

                            return;
                        }

                        // Ends the server-side transaction the way a refused COMMIT does, so the next BEGIN is clean.
                        parent::rollBack();

                        throw new class('COMMIT failed on purpose', '40001', 0) extends AbstractException {};
                    }
                };
            }
        };
    }
}

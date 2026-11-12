<?php

declare(strict_types=1);

namespace App\Tests\Integration\SharedKernel\Infrastructure\Cache;

use App\Organization\Api\PossibleAssignee;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Cache\AfterCommitTagInvalidation;
use App\SharedKernel\Infrastructure\Cache\AfterCommitTagInvalidationMiddleware;
use App\Workflow\Infrastructure\Cache\WorkflowCache;
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
 * AfterCommitTagInvalidationMiddleware on a real PostgreSQL connection, with the workflow cache and the team people
 * cache on pools of their own, as in the app: only the outermost COMMIT that succeeded invalidates; a COMMIT that fails
 * at the driver, a ROLLBACK and a savepoint's release keep both entries, and the first two leave no tenant behind.
 */
final class AfterCommitTagInvalidationMiddlewareTest extends KernelTestCase
{
    private const string ORG_ACME = '0197a3c0-0000-7000-8000-000000002200';
    private const string TEAM_SHOP = '0197a3c0-0000-7000-8000-000000002201';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000002202';

    private AfterCommitTagInvalidation $invalidation;

    private TagAwareAdapter $workflowPool;

    private TagAwareAdapter $teamPeoplePool;

    private WorkflowCache $workflowCache;

    private TeamPeopleCache $teamPeopleCache;

    private Connection $connection;

    /** Set to make the next COMMIT fail at the driver after rolling back there. */
    private bool $isNextCommitFailing = false;

    protected function setUp(): void
    {
        $container = self::getContainer();
        $tenantContext = $container->get(TenantContext::class);
        $tenantContext->set(new OrganizationId(self::ORG_ACME));
        $this->invalidation = new AfterCommitTagInvalidation(new NullLogger());
        $this->workflowPool = new TagAwareAdapter(new ArrayAdapter());
        $this->teamPeoplePool = new TagAwareAdapter(new ArrayAdapter());
        $this->workflowCache = new WorkflowCache($this->workflowPool, $this->invalidation);
        $this->teamPeopleCache = new TeamPeopleCache($this->teamPeoplePool, $this->invalidation);
        $middleware = new AfterCommitTagInvalidationMiddleware($this->invalidation, $tenantContext);
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
    public function aSucceededCommitInvalidatesTheTagsOfBothCaches(): void
    {
        $this->rememberInATransaction('cached before the change');

        $this->connection->beginTransaction();
        $this->queueChangesOfShop();
        $this->connection->commit();

        self::assertSame(['loaded after the commit', 'loaded after the commit'], $this->rememberInATransaction('loaded after the commit'));
    }

    #[Test]
    public function aFailedCommitInvalidatesNoneOfTheTagsAndLeavesNoTenantBehind(): void
    {
        $this->rememberInATransaction('cached before the change');

        $this->connection->beginTransaction();
        $this->queueChangesOfShop();
        $this->commitFailing();

        $this->assertNoTenantIsLeftBehind();
        self::assertSame(['cached before the change', 'cached before the change'], $this->rememberInATransaction('loaded after the failed commit'));
    }

    #[Test]
    public function aRollbackInvalidatesNoneOfTheTagsAndLeavesNoTenantBehind(): void
    {
        $this->rememberInATransaction('cached before the change');

        $this->connection->beginTransaction();
        $this->queueChangesOfShop();
        $this->connection->rollBack();

        $this->assertNoTenantIsLeftBehind();
        self::assertSame(['cached before the change', 'cached before the change'], $this->rememberInATransaction('loaded after the rollback'));
    }

    #[Test]
    public function aSavepointsReleaseInvalidatesNothingAndTheOutermostCommitInvalidatesItsChange(): void
    {
        $this->rememberInATransaction('cached before the change');

        $this->connection->beginTransaction();
        $this->connection->beginTransaction();
        $this->queueChangesOfShop();
        $this->connection->commit();
        self::assertSame(1, $this->connection->getTransactionNestingLevel());
        self::assertSame([true, true], $this->hasShopEntries(), 'The release invalidated nothing.');
        $this->connection->commit();

        self::assertSame([false, false], $this->hasShopEntries());
        self::assertSame(['loaded after the commit', 'loaded after the commit'], $this->rememberInATransaction('loaded after the commit'));
    }

    /**
     * @return array{bool, bool} whether the workflow pool and the team people pool hold Shop's entry
     */
    private function hasShopEntries(): array
    {
        return [
            $this->workflowPool->hasItem('org.'.self::ORG_ACME.'.wf.team.'.self::TEAM_SHOP.'.steps'),
            $this->teamPeoplePool->hasItem('org.'.self::ORG_ACME.'.people.team.'.self::TEAM_SHOP),
        ];
    }

    private function queueChangesOfShop(): void
    {
        $this->workflowCache->invalidateAfterCommit(new OrganizationId(self::ORG_ACME), new TeamId(self::TEAM_SHOP));
        $this->teamPeopleCache->invalidateTeamAfterCommit(new TeamId(self::TEAM_SHOP));
    }

    private function assertNoTenantIsLeftBehind(): void
    {
        self::assertSame(['loaded with no transaction open', 'loaded with no transaction open'], $this->rememberBoth('loaded with no transaction open'));
    }

    /**
     * @return array{string, string} what the workflow cache and the team people cache gave
     */
    private function rememberInATransaction(string $loaded): array
    {
        $this->connection->beginTransaction();
        $remembered = $this->rememberBoth($loaded);
        $this->connection->commit();

        return $remembered;
    }

    /**
     * @return array{string, string} what the workflow cache and the team people cache gave
     */
    private function rememberBoth(string $loaded): array
    {
        $team = new TeamId(self::TEAM_SHOP);
        $people = $this->teamPeopleCache->remember($team, static fn (): array => [new PossibleAssignee(new UserId(self::OMAR), $loaded)]);

        return [$this->workflowCache->remember($team, 'steps', static fn (): string => $loaded), $people[0]->firstName];
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

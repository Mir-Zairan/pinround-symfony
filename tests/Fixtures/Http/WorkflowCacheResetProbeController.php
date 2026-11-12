<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Infrastructure\Cache\WorkflowCache;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Test-only endpoints (config/routes/test_fixtures.yaml), both without the request transaction: the first leaves
 * AfterCommitTagInvalidation inside a transaction whose connection was closed, which no COMMIT or ROLLBACK ends; the second shows
 * whether the next request on the same worker still finds that transaction there.
 */
#[AsController]
final readonly class WorkflowCacheResetProbeController
{
    private const string TEAM_ID = '0197a3c0-0000-7000-8000-000000002211';

    public function __construct(
        private TenantContext $tenantContext,
        private Connection $connection,
        private WorkflowCache $workflowCache,
        #[Autowire(service: 'cache.workflow')]
        private TagAwareCacheInterface $workflowPool,
    ) {
    }

    /**
     * Caches an entry and queues its tag in a transaction of the organization, then closes the connection, which
     * forgets the transaction without a ROLLBACK reaching the driver, and fails.
     */
    public function rememberThenCloseTheConnectionThenFail(string $organizationId): never
    {
        $organization = new OrganizationId($organizationId);
        $team = new TeamId(self::TEAM_ID);
        $this->tenantContext->set($organization);
        $this->connection->beginTransaction();
        $this->workflowCache->remember($team, 'worker-reset-probe', static fn (): string => 'remembered before the connection closed');
        $this->workflowCache->invalidateAfterCommit($organization, $team);
        $this->connection->close();

        throw new \RuntimeException('The controller failed after closing the connection inside a transaction.');
    }

    /**
     * Fills an entry under the team's tag, then invalidates the team with no transaction open, which drops it at once
     * unless AfterCommitTagInvalidation still believes a transaction is open and queues the tag for its commit.
     */
    public function invalidateTheTeamOutsideATransaction(string $organizationId): JsonResponse
    {
        // The tag WorkflowCache gives a team's entries; if it changes, the entry survives and the test fails.
        $this->workflowPool->get('worker-reset-probe', static function (ItemInterface $item): string {
            $item->tag('wf-team-'.self::TEAM_ID);

            return 'filled before the invalidation';
        });

        $this->workflowCache->invalidateAfterCommit(new OrganizationId($organizationId), new TeamId(self::TEAM_ID));

        return new JsonResponse([
            'entry' => $this->workflowPool->get('worker-reset-probe', static fn (): string => 'gone after the invalidation'),
        ]);
    }
}

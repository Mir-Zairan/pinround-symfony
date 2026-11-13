<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Http;

use App\Organization\Api\MemberJob;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Test-only endpoints (config/routes/test_fixtures.yaml), both without the request transaction, like
 * WorkflowCacheResetProbeController for the team people cache: the first leaves it inside a transaction whose
 * connection was closed; the second shows whether the next request on the same worker still finds that transaction.
 */
#[AsController]
final readonly class TeamPeopleCacheResetProbeController
{
    private const string TEAM_ID = '0197a3c0-0000-7000-8000-000000002212';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000002213';

    public function __construct(
        private TenantContext $tenantContext,
        private Connection $connection,
        private TeamPeopleCache $teamPeopleCache,
        #[Autowire(service: 'cache.team_people')]
        private TagAwareCacheInterface $teamPeoplePool,
    ) {
    }

    /**
     * Caches the team in a transaction of the organization, then closes the connection, which forgets the transaction
     * without a ROLLBACK reaching the driver, and fails. It queues no change, so that a transaction the reset left
     * behind would let the next request read and fill entries under this organization.
     */
    public function rememberThenCloseTheConnectionThenFail(string $organizationId): never
    {
        $team = new TeamId(self::TEAM_ID);
        $this->tenantContext->set(new OrganizationId($organizationId));
        $this->connection->beginTransaction();
        $this->teamPeopleCache->remember($team, static fn (): array => [new PossibleAssignee(new UserId(self::OMAR), 'Omar', 'Omar Demir', MemberJob::Developer, false)]);
        $this->connection->close();

        throw new \RuntimeException('The controller failed after closing the connection inside a transaction.');
    }

    /**
     * Reads the team twice with no transaction open, which loads both times unless the cache still has the failed
     * request's organization; then fills an entry under the team's tag and invalidates the team, which drops it at once
     * unless the cache still believes a transaction is open and queues the tag for its commit.
     */
    public function invalidateTheTeamOutsideATransaction(): JsonResponse
    {
        $loads = 0;
        $load = static function () use (&$loads): array {
            ++$loads;

            return [];
        };
        $this->teamPeopleCache->remember(new TeamId(self::TEAM_ID), $load);
        $this->teamPeopleCache->remember(new TeamId(self::TEAM_ID), $load);

        // The tag TeamPeopleCache gives a team's entries; if it changes, the entry survives and the test fails.
        $this->teamPeoplePool->get('worker-reset-probe', static function (ItemInterface $item): string {
            $item->tag('people-team-'.self::TEAM_ID);

            return 'filled before the invalidation';
        });

        $this->teamPeopleCache->invalidateTeamAfterCommit(new TeamId(self::TEAM_ID));

        return new JsonResponse([
            'entry' => $this->teamPeoplePool->get('worker-reset-probe', static fn (): string => 'gone after the invalidation'),
            'loads' => $loads,
        ]);
    }
}

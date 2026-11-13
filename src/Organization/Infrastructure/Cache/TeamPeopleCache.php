<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Cache;

use App\Organization\Api\PossibleAssignee;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Cache\AfterCommitTagInvalidation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * The cache.team_people pool for one team's people and their names (performance-and-scale.md, "Caching"). An entry is
 * keyed by the organization of the open transaction, so it is filled and read only under row-level security of that
 * organization; it is tagged by its team, and by each person in it, so a new name clears every team that shows it. A
 * change of a team queues its tag with invalidateTeamAfterCommit(); SharedKernel's AfterCommitTagInvalidation
 * invalidates it once the outermost transaction has committed.
 */
final readonly class TeamPeopleCache
{
    public function __construct(
        #[Autowire(service: 'cache.team_people')]
        private TagAwareCacheInterface $pool,
        private AfterCommitTagInvalidation $invalidation,
    ) {
    }

    /**
     * Reads the team's people from the cache, or runs $load and keeps its answer for a day. With no transaction open,
     * one opened with no tenant, or a change of this transaction waiting for its commit, it runs $load and keeps
     * nothing: the rows it reads would be another tenant's, none, or not yet committed.
     *
     * @param \Closure(): list<PossibleAssignee> $load
     *
     * @return list<PossibleAssignee>
     */
    public function remember(TeamId $team, \Closure $load): array
    {
        $organization = $this->invalidation->tenantToCacheFor($this->pool);
        if (null === $organization) {
            return $load();
        }

        // Raise the version when PossibleAssignee changes shape, so an entry kept before is never read back.
        $key = \sprintf('org.%s.people.v2.team.%s', $organization->toString(), $team->toString());

        return $this->pool->get($key, static function (ItemInterface $item) use ($load, $team): array {
            $people = $load();
            $item->tag([
                self::teamTag($team),
                ...array_map(static fn (PossibleAssignee $person): string => self::personTag($person->userId), $people),
            ]);

            return $people;
        });
    }

    /**
     * Queues the entry a change of the team's people (one joins, leaves or changes role) makes stale. It is invalidated
     * when the open transaction commits and kept when it rolls back; with no transaction open it is invalidated now.
     *
     * @throws \RuntimeException when no transaction is open and the pool could not invalidate the tag
     */
    public function invalidateTeamAfterCommit(TeamId $team): void
    {
        $this->invalidation->invalidateAfterCommit($this->pool, [self::teamTag($team)]);
    }

    /**
     * Invalidates now what invalidateTeamAfterCommit() would queue.
     *
     * @throws \RuntimeException when the pool could not invalidate the tag, so a message handler is retried
     */
    public function invalidateTeamNow(TeamId $team): void
    {
        $this->invalidation->invalidateNow($this->pool, [self::teamTag($team)]);
    }

    /**
     * Invalidates now every team the person is in, of any organization: their name changed.
     *
     * @throws \RuntimeException when the pool could not invalidate the tag, so a message handler is retried
     */
    public function invalidatePersonNow(UserId $person): void
    {
        $this->invalidation->invalidateNow($this->pool, [self::personTag($person)]);
    }

    private static function teamTag(TeamId $team): string
    {
        return 'people-team-'.$team->toString();
    }

    private static function personTag(UserId $person): string
    {
        return 'people-user-'.$person->toString();
    }
}

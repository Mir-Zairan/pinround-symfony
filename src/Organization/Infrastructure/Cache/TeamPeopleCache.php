<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Cache;

use App\Organization\Api\PossibleAssignee;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The cache.team_people pool for one team's people and their names (performance-and-scale.md, "Caching"). An entry is
 * keyed by the organization of the open transaction, so it is filled and read only under row-level security of that
 * organization; it is tagged by its team, and by each person in it, so a new name clears every team that shows it. A change
 * queues its tags with invalidate*AfterCommit(); TeamPeopleCacheTransactionMiddleware tells this class when the
 * outermost transaction begins and ends, and the tags are invalidated only once it has committed.
 */
final class TeamPeopleCache implements ResetInterface
{
    private bool $isTransactionOpen = false;

    private ?OrganizationId $transactionTenant = null;

    /** @var array<string, true> tag => true, invalidated when the open transaction commits */
    private array $waitingTags = [];

    public function __construct(
        #[Autowire(service: 'cache.team_people')]
        private readonly TagAwareCacheInterface $pool,
        private readonly LoggerInterface $logger,
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
        if (null === $this->transactionTenant || [] !== $this->waitingTags) {
            return $load();
        }

        $key = \sprintf('org.%s.people.team.%s', $this->transactionTenant->toString(), $team->toString());

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
        $this->invalidateAfterCommit([self::teamTag($team)]);
    }

    /**
     * Like invalidateTeamAfterCommit(), for every team the person is in, of any organization: their name changed.
     *
     * @throws \RuntimeException when no transaction is open and the pool could not invalidate the tag
     */
    public function invalidatePersonAfterCommit(UserId $person): void
    {
        $this->invalidateAfterCommit([self::personTag($person)]);
    }

    /**
     * Invalidates now what invalidateTeamAfterCommit() would queue.
     *
     * @throws \RuntimeException when the pool could not invalidate the tag, so a message handler is retried
     */
    public function invalidateTeamNow(TeamId $team): void
    {
        $this->invalidate([self::teamTag($team)]);
    }

    /**
     * @internal called by TeamPeopleCacheTransactionMiddleware right after an outermost BEGIN; tags a transaction that
     *           ended without COMMIT or ROLLBACK reaching the driver (a closed connection) left behind are dropped
     */
    public function transactionBegan(?OrganizationId $tenant): void
    {
        $this->isTransactionOpen = true;
        $this->transactionTenant = $tenant;
        $this->waitingTags = [];
    }

    /**
     * @internal called by TeamPeopleCacheTransactionMiddleware after the outermost COMMIT succeeded
     *
     * Never throws: the change is committed already. Entries of a team the pool failed to drop stay until the relayed
     * TeamRoleChanged invalidates them again (InvalidateTeamPeopleCacheHandler); entries of a renamed person stay until
     * they expire, at most a day.
     */
    public function transactionCommitted(): void
    {
        $tags = array_keys($this->waitingTags);
        $this->transactionEnded();
        if ([] === $tags) {
            return;
        }

        try {
            $this->invalidate($tags);
        } catch (\RuntimeException $failure) {
            $this->logger->warning('The team people cache kept entries of a committed change.', ['exception_class' => $failure::class]);
        }
    }

    /**
     * @internal called by TeamPeopleCacheTransactionMiddleware when the outermost transaction rolls back or its commit fails
     */
    public function transactionEnded(): void
    {
        $this->isTransactionOpen = false;
        $this->transactionTenant = null;
        $this->waitingTags = [];
    }

    public function reset(): void
    {
        $this->transactionEnded();
    }

    /**
     * @param list<string> $tags
     */
    private function invalidateAfterCommit(array $tags): void
    {
        if (!$this->isTransactionOpen) {
            $this->invalidate($tags);

            return;
        }

        foreach ($tags as $tag) {
            $this->waitingTags[$tag] = true;
        }
    }

    /**
     * @param list<string> $tags
     *
     * @throws \RuntimeException when the pool reports a failure
     */
    private function invalidate(array $tags): void
    {
        if (!$this->pool->invalidateTags($tags)) {
            throw new \RuntimeException('The team people cache could not invalidate its tags.');
        }
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

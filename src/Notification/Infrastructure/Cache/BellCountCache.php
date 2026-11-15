<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Cache;

use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Cache\AfterCommitTagInvalidation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * The cache.bell_count pool: the count on a person's bell, kept 60 seconds (realtime-and-notifications.md, "The
 * bell"). An entry is keyed by the organization of the open transaction, so it is filled and read only under its
 * row-level security, and tagged by the person. Whatever adds, finishes or releases one of their notifications calls
 * invalidateAfterCommit(); SharedKernel's AfterCommitTagInvalidation drops the entry once that transaction commits.
 */
final readonly class BellCountCache
{
    public function __construct(
        #[Autowire(service: 'cache.bell_count')]
        private TagAwareCacheInterface $pool,
        private AfterCommitTagInvalidation $invalidation,
    ) {
    }

    /**
     * Reads the count from the cache, or runs $load and keeps its answer. With no transaction open, one opened with no
     * tenant, or a change of this transaction waiting for its commit, it runs $load and keeps nothing.
     *
     * @param \Closure(): int $load
     */
    public function remember(UserId $userId, \Closure $load): int
    {
        $organization = $this->invalidation->tenantToCacheFor($this->pool);
        if (null === $organization) {
            return $load();
        }

        $key = \sprintf('org.%s.bell-count.v1.user.%s', $organization->toString(), $userId->toString());

        return $this->pool->get($key, static function (ItemInterface $item) use ($load, $userId): int {
            $item->tag(self::userTag($userId));

            return $load();
        });
    }

    /**
     * Queues the person's count for the commit of the open transaction; with no transaction open it is dropped now.
     *
     * @throws \RuntimeException when no transaction is open and the pool could not invalidate the tag
     */
    public function invalidateAfterCommit(UserId $userId): void
    {
        $this->invalidation->invalidateAfterCommit($this->pool, [self::userTag($userId)]);
    }

    private static function userTag(UserId $userId): string
    {
        return 'bell-count-user-'.$userId->toString();
    }
}

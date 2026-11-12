<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Cache;

use App\SharedKernel\Domain\OrganizationId;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Invalidates the tags a change queues in a tag-aware pool only once the outermost transaction on the default connection
 * has committed, and drops them when it rolls back: invalidating before the commit would let a reader fill the entry
 * again from the old rows. AfterCommitTagInvalidationMiddleware tells it where that transaction begins and ends. It also
 * tells a cache under which organization it may read and fill entries, as the rows behind them are read under the
 * row-level security of the transaction's tenant (performance-and-scale.md, "Caching"). Every pool shares one
 * transaction, but each keeps its own waiting tags.
 */
final class AfterCommitTagInvalidation implements ResetInterface
{
    private bool $isTransactionOpen = false;

    private ?OrganizationId $transactionTenant = null;

    /** @var array<int, array{pool: TagAwareCacheInterface, tags: non-empty-array<string, true>}> by spl_object_id() of the pool */
    private array $waitingChanges = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * The organization whose entries of $pool may be read and filled now: the open transaction's tenant. Null with no
     * transaction open, one opened with no tenant, or a change of $pool waiting for its commit, as the rows a fill reads
     * would then be another tenant's, none, or not yet committed; the cache then loads and keeps nothing.
     */
    public function tenantToCacheFor(TagAwareCacheInterface $pool): ?OrganizationId
    {
        return isset($this->waitingChanges[spl_object_id($pool)]) ? null : $this->transactionTenant;
    }

    /**
     * Queues the tags for the commit of the open transaction. With no transaction open they are invalidated now.
     *
     * @param non-empty-list<string> $tags
     *
     * @throws \RuntimeException when no transaction is open and the pool could not invalidate the tags
     */
    public function invalidateAfterCommit(TagAwareCacheInterface $pool, array $tags): void
    {
        if (!$this->isTransactionOpen) {
            $this->invalidateNow($pool, $tags);

            return;
        }

        $poolId = spl_object_id($pool);
        $this->waitingChanges[$poolId] = [
            'pool' => $pool,
            'tags' => array_fill_keys($tags, true) + ($this->waitingChanges[$poolId]['tags'] ?? []),
        ];
    }

    /**
     * @param non-empty-list<string> $tags
     *
     * @throws \RuntimeException when the pool could not invalidate the tags, so a message handler is retried
     */
    public function invalidateNow(TagAwareCacheInterface $pool, array $tags): void
    {
        if (!$pool->invalidateTags($tags)) {
            throw new \RuntimeException('A cache pool could not invalidate its tags.');
        }
    }

    /**
     * @internal called by AfterCommitTagInvalidationMiddleware right after an outermost BEGIN; tags a transaction that
     *           ended without COMMIT or ROLLBACK reaching the driver (a closed connection) left behind are dropped
     */
    public function transactionBegan(?OrganizationId $tenant): void
    {
        $this->isTransactionOpen = true;
        $this->transactionTenant = $tenant;
        $this->waitingChanges = [];
    }

    /**
     * @internal called by AfterCommitTagInvalidationMiddleware after the outermost COMMIT succeeded
     *
     * Never throws: the change is committed already. Entries a pool failed to drop stay until the relayed event of the
     * change invalidates them again (each cache's event handler).
     */
    public function transactionCommitted(): void
    {
        $committedChanges = $this->waitingChanges;
        $this->transactionEnded();
        foreach ($committedChanges as ['pool' => $pool, 'tags' => $tags]) {
            try {
                $this->invalidateNow($pool, array_keys($tags));
            } catch (\RuntimeException $failure) {
                $this->logger->warning('A cache kept entries of a committed change; its event handler drops them.', ['exception_class' => $failure::class]);
            }
        }
    }

    /**
     * @internal called by AfterCommitTagInvalidationMiddleware when the outermost transaction rolls back or its commit fails
     */
    public function transactionEnded(): void
    {
        $this->isTransactionOpen = false;
        $this->transactionTenant = null;
        $this->waitingChanges = [];
    }

    public function reset(): void
    {
        $this->transactionEnded();
    }
}

<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Cache;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The cache.workflow pool for one team's steps and rules (performance-and-scale.md, "Caching"). An entry is keyed and
 * tagged by the organization of the open transaction, so it is filled and read only under row-level security of that
 * organization. A change queues its tags with invalidateAfterCommit(); WorkflowCacheTransactionMiddleware tells this
 * class when the outermost transaction begins and ends, and the tags are invalidated only once it has committed.
 */
final class WorkflowCache implements ResetInterface
{
    private bool $isTransactionOpen = false;

    private ?OrganizationId $transactionTenant = null;

    /** @var array<string, true> tag => true, invalidated when the open transaction commits */
    private array $waitingTags = [];

    public function __construct(
        #[Autowire(service: 'cache.workflow')]
        private readonly TagAwareCacheInterface $pool,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Reads $entry of the team from the cache, or runs $load and keeps its answer for a day. With no transaction open,
     * one opened with no tenant, or a change of this transaction waiting for its commit, it runs $load and keeps
     * nothing: the rows it reads would be another tenant's, none, or not yet committed.
     *
     * @template T
     *
     * @param string        $entry what is cached for the team, made of letters, digits, '-' and '.' only; anything
     *                             the answer depends on besides the team and the organization goes in it
     * @param \Closure(): T $load
     *
     * @return T
     */
    public function remember(TeamId $team, string $entry, \Closure $load): mixed
    {
        if (null === $this->transactionTenant || [] !== $this->waitingTags) {
            return $load();
        }

        $organization = $this->transactionTenant;
        $key = \sprintf('org.%s.wf.team.%s.%s', $organization->toString(), $team->toString(), $entry);
        $tags = [self::teamTag($team), self::organizationTag($organization)];

        /* @var T */
        return $this->pool->get($key, static function (ItemInterface $item) use ($load, $tags): mixed {
            $item->tag($tags);

            return $load();
        });
    }

    /**
     * Queues the entries a change of the team's steps or rules (or, with no team, of the organization's own) makes
     * stale. They are invalidated when the open transaction commits and kept when it rolls back. With no transaction
     * open they are invalidated now.
     */
    public function invalidateAfterCommit(OrganizationId $organization, ?TeamId $team): void
    {
        $tag = null === $team ? self::organizationTag($organization) : self::teamTag($team);
        if (!$this->isTransactionOpen) {
            $this->invalidate([$tag]);

            return;
        }

        $this->waitingTags[$tag] = true;
    }

    /**
     * Invalidates now what invalidateAfterCommit() would queue.
     *
     * @throws \RuntimeException when the pool could not invalidate the tag, so a message handler is retried
     */
    public function invalidateNow(OrganizationId $organization, ?TeamId $team): void
    {
        $this->invalidate([null === $team ? self::organizationTag($organization) : self::teamTag($team)]);
    }

    /**
     * @internal called by WorkflowCacheTransactionMiddleware right after an outermost BEGIN; tags a transaction that
     *           ended without COMMIT or ROLLBACK reaching the driver (a closed connection) left behind are dropped
     */
    public function transactionBegan(?OrganizationId $tenant): void
    {
        $this->isTransactionOpen = true;
        $this->transactionTenant = $tenant;
        $this->waitingTags = [];
    }

    /**
     * @internal called by WorkflowCacheTransactionMiddleware after the outermost COMMIT succeeded
     *
     * Never throws: the change is committed already. Entries the pool failed to drop stay until the event handler of
     * the change invalidates them again (InvalidateWorkflowCacheHandler).
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
            $this->logger->warning('The workflow cache kept entries of a committed change; its event handler drops them.', ['exception_class' => $failure::class]);
        }
    }

    /**
     * @internal called by WorkflowCacheTransactionMiddleware when the outermost transaction rolls back or its commit fails
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
     *
     * @throws \RuntimeException when the pool reports a failure
     */
    private function invalidate(array $tags): void
    {
        if (!$this->pool->invalidateTags($tags)) {
            throw new \RuntimeException('The workflow cache could not invalidate its tags.');
        }
    }

    private static function teamTag(TeamId $team): string
    {
        return 'wf-team-'.$team->toString();
    }

    private static function organizationTag(OrganizationId $organization): string
    {
        return 'wf-org-'.$organization->toString();
    }
}

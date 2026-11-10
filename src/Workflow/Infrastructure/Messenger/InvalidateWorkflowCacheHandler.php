<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Messenger;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Api\Event\WorkflowEvent;
use App\Workflow\Infrastructure\Cache\WorkflowCache;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The second invalidation of a workflow change, in worker-notify once the relayed event arrives: it drops an entry a
 * reader loaded from the old rows just before the change committed and saved just after the first invalidation
 * (WorkflowCache::transactionCommitted()), and one the first invalidation failed to drop. Invalidating is safe to
 * repeat, so it does not go through OnceOnlyHandling.
 */
#[AsMessageHandler(bus: 'event.bus')]
final readonly class InvalidateWorkflowCacheHandler
{
    public function __construct(private WorkflowCache $workflowCache)
    {
    }

    /**
     * @throws \RuntimeException when the pool could not invalidate, so the event is retried
     */
    public function __invoke(WorkflowEvent $event): void
    {
        $this->workflowCache->invalidateNow(
            new OrganizationId($event->organizationId),
            null === $event->teamId ? null : new TeamId($event->teamId),
        );
    }
}

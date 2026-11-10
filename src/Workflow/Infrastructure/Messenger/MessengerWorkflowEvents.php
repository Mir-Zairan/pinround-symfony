<?php

declare(strict_types=1);

namespace App\Workflow\Infrastructure\Messenger;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Workflow\Api\Event\WorkflowEvent;
use App\Workflow\Application\WorkflowEvents;
use App\Workflow\Infrastructure\Cache\WorkflowCache;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches on event.bus, which routes every WorkflowEvent to the outbox (messenger.yaml); its doctrine_transaction
 * joins the open transaction as a savepoint, so the outbox row commits or rolls back with it. Every event names the
 * team (or the organization) whose steps or rules changed, so publishing it also queues the cached entries it makes
 * stale, invalidated when the transaction commits.
 */
final readonly class MessengerWorkflowEvents implements WorkflowEvents
{
    public function __construct(
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private Connection $connection,
        private WorkflowCache $workflowCache,
    ) {
    }

    public function publish(WorkflowEvent $event): void
    {
        // Outside a transaction doctrine_transaction would commit the outbox row on its own, before the change.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A workflow event is published in the transaction of its change; none is open.');
        }

        $this->eventBus->dispatch($event);
        $this->workflowCache->invalidateAfterCommit(
            new OrganizationId($event->organizationId),
            null === $event->teamId ? null : new TeamId($event->teamId),
        );
    }
}

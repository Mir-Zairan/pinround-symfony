<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Messenger;

use App\Organization\Api\Event\OrganizationEvent;
use App\Organization\Application\OrganizationEvents;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches on event.bus, which routes every OrganizationEvent to the outbox (messenger.yaml); its
 * doctrine_transaction joins the open transaction as a savepoint, so the outbox row commits or rolls back with it.
 */
final readonly class MessengerOrganizationEvents implements OrganizationEvents
{
    public function __construct(
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private Connection $connection,
    ) {
    }

    public function publish(OrganizationEvent $event): void
    {
        // Outside a transaction doctrine_transaction would commit the outbox row on its own, before the change.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('An organization event is published in the transaction of its change; none is open.');
        }

        $this->eventBus->dispatch($event);
    }
}

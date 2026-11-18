<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Messenger;

use App\Identity\Api\Event\IdentityEvent;
use App\Identity\Application\IdentityEvents;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches on event.bus, which routes every IdentityEvent to the outbox (messenger.yaml); its doctrine_transaction
 * joins the open transaction as a savepoint, so the outbox row commits or rolls back with the change, and other modules
 * handle it only after the commit, once the relay has moved it to async_notify.
 */
final readonly class MessengerIdentityEvents implements IdentityEvents
{
    public function __construct(
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private Connection $connection,
    ) {
    }

    /**
     * @throws \LogicException when no transaction is open
     */
    public function publish(IdentityEvent $event): void
    {
        // Outside a transaction doctrine_transaction would commit the outbox row on its own, before the change.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('An identity event is published in the transaction of its change; none is open.');
        }

        $this->eventBus->dispatch($event);
    }
}

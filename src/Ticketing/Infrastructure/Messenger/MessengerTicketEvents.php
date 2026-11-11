<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Messenger;

use App\Ticketing\Api\Event\TicketEvent;
use App\Ticketing\Application\TicketEvents;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches on event.bus, which routes every TicketEvent to the outbox (messenger.yaml); its doctrine_transaction
 * joins the open transaction as a savepoint, so the outbox row commits or rolls back with it.
 */
final readonly class MessengerTicketEvents implements TicketEvents
{
    public function __construct(
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private Connection $connection,
    ) {
    }

    public function publish(TicketEvent $event): void
    {
        // Outside a transaction doctrine_transaction would commit the outbox row on its own, before the change.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A ticket event is published in the transaction of its change; none is open.');
        }

        $this->eventBus->dispatch($event);
    }
}

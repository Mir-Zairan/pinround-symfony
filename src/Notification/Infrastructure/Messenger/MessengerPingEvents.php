<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Messenger;

use App\Notification\Api\Event\PingEvent;
use App\Notification\Application\Ping\PingEvents;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches on event.bus, which routes every NotificationEvent to the outbox (messenger.yaml); its
 * doctrine_transaction joins the open transaction as a savepoint, so the outbox row commits or rolls back with it.
 */
final readonly class MessengerPingEvents implements PingEvents
{
    public function __construct(
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private Connection $connection,
    ) {
    }

    public function publish(PingEvent $event): void
    {
        // Outside a transaction doctrine_transaction would commit the outbox row on its own, before the change.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A ping event is published in the transaction of its change; none is open.');
        }

        $this->eventBus->dispatch($event);
    }
}

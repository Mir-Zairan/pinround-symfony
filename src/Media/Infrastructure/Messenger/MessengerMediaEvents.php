<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Messenger;

use App\Media\Api\Event\MediaEvent;
use App\Media\Application\Processing\MediaEvents;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches on event.bus, which routes every MediaEvent to the outbox (messenger.yaml); its doctrine_transaction
 * joins the open transaction as a savepoint, so the outbox row commits or rolls back with the change.
 */
final readonly class MessengerMediaEvents implements MediaEvents
{
    public function __construct(
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private Connection $connection,
    ) {
    }

    public function publish(MediaEvent $event): void
    {
        // Outside a transaction doctrine_transaction would commit the outbox row on its own, before the change.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A media event is published in the transaction of its change; none is open.');
        }

        $this->eventBus->dispatch($event);
    }
}

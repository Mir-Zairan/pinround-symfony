<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Messenger;

use App\Identity\Api\Event\UserRenamed;
use App\Identity\Application\IdentityEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches on event.bus. No route sends an Identity event to the outbox (messenger.yaml), so its handlers run at once,
 * inside the caller's transaction.
 */
final readonly class MessengerIdentityEvents implements IdentityEvents
{
    public function __construct(
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
    ) {
    }

    public function publish(UserRenamed $event): void
    {
        $this->eventBus->dispatch($event);
    }
}

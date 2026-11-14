<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Mercure;

use App\Conversation\Api\Event\ConversationEvent;
use App\Notification\Api\Event\PingEvent;
use App\Ticketing\Api\Event\TicketEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a refresh of the pages open on a ticket for every change to it, in worker-notify once the relayed event
 * arrives, never in the request that made the change. The publish goes in a message of its own: the handlers of a
 * received event share one transaction (messenger.yaml › event.bus), and a hub that is down must not roll back the
 * others. Of the Notification events only a PingEvent is about a ticket.
 */
final readonly class QueueTicketPagesRefreshHandler
{
    public function __construct(#[Autowire(service: 'command.bus')] private MessageBusInterface $commandBus)
    {
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onTicketEvent(TicketEvent $event): void
    {
        $this->queueRefresh($event->organizationId, $event->ticketId);
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onConversationEvent(ConversationEvent $event): void
    {
        $this->queueRefresh($event->organizationId, $event->ticketId);
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onPingEvent(PingEvent $event): void
    {
        $this->queueRefresh($event->organizationId, $event->ticketId);
    }

    private function queueRefresh(string $organizationId, string $ticketId): void
    {
        $this->commandBus->dispatch(new RefreshTicketPages($organizationId, $ticketId, RefreshAttempt::Leading));
    }
}

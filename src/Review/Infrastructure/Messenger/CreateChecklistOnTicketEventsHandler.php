<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Messenger;

use App\Review\Api\ChecklistCommands;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\Event\TicketCreated;
use App\Ticketing\Api\Event\TicketMoved;
use App\Ticketing\Api\TicketTurnQueries;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Makes a ticket's checklist round from Round 0's pins (FR-RND-04, P05.T6) in worker-notify once the relayed event
 * arrives: on the first "Start work", or when the ticket is in To do. The event's organization is the tenant and its
 * transaction is open, as for every handler on the event bus. createFromReport makes nothing when the checklist exists
 * already, so a redelivered event is safe without OnceOnlyHandling. A Round 0 added after the ticket reached To do gets
 * its checklist from RoundCommands::createReport instead.
 */
final readonly class CreateChecklistOnTicketEventsHandler
{
    private const string START_WORK_VERB = 'start_work';
    private const string TODO_CORE_CODE = 'todo';

    public function __construct(
        private ChecklistCommands $checklistCommands,
        private TicketTurnQueries $ticketTurnQueries,
        private ClockInterface $clock,
    ) {
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onTicketCreated(TicketCreated $event): void
    {
        $this->createWhenInToDo(new TicketId($event->ticketId));
    }

    #[AsMessageHandler(bus: 'event.bus')]
    public function onTicketMoved(TicketMoved $event): void
    {
        $ticketId = new TicketId($event->ticketId);
        if (self::START_WORK_VERB === $event->verb) {
            $this->checklistCommands->createFromReport($ticketId, $this->clock->now());

            return;
        }
        $this->createWhenInToDo($ticketId);
    }

    private function createWhenInToDo(TicketId $ticketId): void
    {
        $ticket = $this->ticketTurnQueries->factsOf($ticketId);
        if (self::TODO_CORE_CODE === $ticket?->coreCode) {
            $this->checklistCommands->createFromReport($ticketId, $this->clock->now());
        }
    }
}

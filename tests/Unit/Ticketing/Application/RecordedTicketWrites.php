<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\Insight\Api\TurnProjector;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\Event\TicketEvent;
use App\Ticketing\Application\TicketEvents;
use App\Ticketing\Application\TicketHistory;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\TicketKey;
use App\Ticketing\Domain\Tickets;

/**
 * Every write a ticket command makes, in the order it makes them: the saved ticket, its history row, its turn refresh
 * and its event. New tickets get the given id; ofKey finds an added ticket as DoctrineTickets would, only in its own
 * organization.
 */
final class RecordedTicketWrites implements Tickets, TicketHistory, TurnProjector, TicketEvents
{
    /** @var list<string> one line per write, for example "turn <ticket id>" */
    public array $writes = [];

    /** @var list<Ticket> */
    public array $addedTickets = [];

    /** @var list<TicketEvent> */
    public array $publishedEvents = [];

    public function __construct(private readonly string $nextTicketId)
    {
    }

    public function nextIdentity(): TicketId
    {
        return new TicketId($this->nextTicketId);
    }

    public function add(Ticket $ticket): void
    {
        $this->addedTickets[] = $ticket;
        $this->writes[] = 'ticket '.$ticket->id()->toString();
    }

    public function ofKey(OrganizationId $organizationId, TicketKey $key): ?Ticket
    {
        return array_find(
            $this->addedTickets,
            static fn (Ticket $ticket): bool => $ticket->organizationId()->equals($organizationId) && $ticket->key()->equals($key),
        );
    }

    public function recordCreated(OrganizationId $organizationId, TicketId $ticketId, UserId $reporter, string $stepId, \DateTimeImmutable $at): void
    {
        $this->writes[] = \sprintf('created %s by %s in %s at %s', $ticketId->toString(), $reporter->toString(), $stepId, $at->format('Y-m-d H:i'));
    }

    public function refresh(TicketId $ticketId): ?string
    {
        $this->writes[] = 'turn '.$ticketId->toString();

        return null;
    }

    public function refreshFrom(TicketTurnSnapshot $snapshot): ?string
    {
        $this->writes[] = 'turn from snapshot '.$snapshot->ticketId->toString();

        return null;
    }

    public function publish(TicketEvent $event): void
    {
        $this->publishedEvents[] = $event;
        $this->writes[] = 'event '.$event::class;
    }
}

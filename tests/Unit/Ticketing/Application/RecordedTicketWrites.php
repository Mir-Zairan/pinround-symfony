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
use App\Ticketing\Domain\TicketVersionConflict;

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

    /** @var list<string> */
    private array $ticketsChangedMeanwhile = [];

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

    /**
     * As DoctrineTickets, it saves only a ticket it holds, and refuses one marked with changedMeanwhile.
     */
    public function save(Ticket $ticket): void
    {
        if (!\in_array($ticket, $this->addedTickets, true)) {
            throw new \LogicException('Only a ticket loaded in this request can be saved; a new one is added.');
        }
        if (\in_array($ticket->id()->toString(), $this->ticketsChangedMeanwhile, true)) {
            throw new TicketVersionConflict('The ticket was saved by another request after it was read.');
        }

        $this->writes[] = 'saved ticket '.$ticket->id()->toString();
    }

    /**
     * Another request saves the ticket after the command read it, so the command's save conflicts.
     */
    public function changedMeanwhile(TicketId $ticketId): void
    {
        $this->ticketsChangedMeanwhile[] = $ticketId->toString();
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

    public function recordMoved(
        OrganizationId $organizationId,
        TicketId $ticketId,
        UserId $actor,
        string $fromStepId,
        string $toStepId,
        ?string $verb,
        ?string $overrideNote,
        \DateTimeImmutable $at,
    ): void {
        $this->writes[] = \sprintf(
            'moved %s by %s from %s to %s with verb %s and note %s at %s',
            $ticketId->toString(),
            $actor->toString(),
            $fromStepId,
            $toStepId,
            $verb ?? '-',
            $overrideNote ?? '-',
            $at->format('Y-m-d H:i'),
        );
    }

    public function refresh(TicketId $ticketId): ?string
    {
        $this->writes[] = 'turn '.$ticketId->toString();

        return null;
    }

    public function refreshFrom(TicketTurnSnapshot $snapshot, ?array $waitingTurns = null): ?string
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

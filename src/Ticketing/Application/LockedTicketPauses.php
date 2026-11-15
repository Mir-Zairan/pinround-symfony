<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\AskedPersonNotOnTicket;
use App\Ticketing\Api\TicketAlreadyPaused;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketPauses;
use App\Ticketing\Domain\QuestionAlreadyOpen;
use App\Ticketing\Domain\Ticket;
use App\Ticketing\Domain\Tickets;
use App\Ticketing\Domain\TicketVersionConflict;

final readonly class LockedTicketPauses implements TicketPauses
{
    public function __construct(
        private Tickets $tickets,
        private TicketTurnSnapshots $ticketTurnSnapshots,
        private TenantContext $tenantContext,
    ) {
    }

    public function pauseForQuestion(TicketId $ticketId, UserId $askedUserId, UserId $askerId): void
    {
        $ticket = $this->lockedTicket($ticketId);
        // Read from the locked row, so a reassignment saved since the caller read the ticket cannot slip past it.
        if (!self::isOnTheTicket($this->ticketTurnSnapshots->of($ticket)->peopleOnTheTicket(), $askedUserId)) {
            throw new AskedPersonNotOnTicket('The person asked is not on this ticket.');
        }
        try {
            $ticket->pauseForQuestion($askedUserId, $askerId);
        } catch (QuestionAlreadyOpen $refusal) {
            throw new TicketAlreadyPaused($refusal->getMessage(), previous: $refusal);
        }
        $this->save($ticket);
    }

    public function endQuestionPause(TicketId $ticketId): void
    {
        $ticket = $this->lockedTicket($ticketId);
        $ticket->resume();
        $this->save($ticket);
    }

    /**
     * @param list<UserId> $peopleOnTheTicket
     */
    private static function isOnTheTicket(array $peopleOnTheTicket, UserId $userId): bool
    {
        return array_any($peopleOnTheTicket, static fn ($personId) => $personId->equals($userId));
    }

    private function lockedTicket(TicketId $ticketId): Ticket
    {
        try {
            $ticket = $this->tickets->lockedOf($this->tenantContext->require(), $ticketId);
        } catch (TicketVersionConflict $conflict) {
            throw new TicketChangedMeanwhile('The ticket was changed after it was read.', previous: $conflict);
        }

        return $ticket ?? throw new TicketNotFound('The organization has no ticket with this id.');
    }

    private function save(Ticket $ticket): void
    {
        try {
            $this->tickets->save($ticket);
        } catch (TicketVersionConflict $conflict) {
            throw new TicketChangedMeanwhile('The ticket was changed after it was read.', previous: $conflict);
        }
    }
}

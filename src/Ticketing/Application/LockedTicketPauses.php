<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
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
        if (!self::isOnTheTicket($this->ticketTurnSnapshots->of($ticket), $askedUserId)) {
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

    private static function isOnTheTicket(TicketTurnSnapshot $ticket, UserId $userId): bool
    {
        foreach ([$ticket->assigneeId, $ticket->reporterId, $ticket->reviewerId, $ticket->testerId, $ticket->leadId] as $listedId) {
            if (null !== $listedId && $listedId->equals($userId)) {
                return true;
            }
        }

        return false;
    }

    private function lockedTicket(TicketId $ticketId): Ticket
    {
        return $this->tickets->lockedOf($this->tenantContext->require(), $ticketId)
            ?? throw new TicketNotFound('The organization has no ticket with this id.');
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

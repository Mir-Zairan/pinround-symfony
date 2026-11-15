<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The question pause of a ticket, for Conversation's AskQuestion, Answer and WithdrawQuestion. Each method locks the
 * ticket's row in the organization of TenantContext until the caller's transaction ends, so two requests cannot both
 * pass its check, and saves the ticket at once. The step stays as it is. The caller checks who may ask, answer and
 * withdraw, and refreshes the ticket's turn after the call in the same transaction.
 */
interface TicketPauses
{
    /**
     * Pauses the ticket until the asked person answers the asker's question. Whether the asked person is on the
     * ticket is decided on the locked row, so a reassignment saved meanwhile counts.
     *
     * @throws TicketNotFound            when the organization has no ticket with the id
     * @throws AskedPersonNotOnTicket    when the asked person is not the ticket's assignee, reporter, reviewer or
     *                                   tester nor its team's lead; nothing is saved
     * @throws TicketAlreadyPaused       when another question already pauses the ticket; nothing is saved
     * @throws TicketChangedMeanwhile    when another request saved the ticket after this request read it; the caller
     *                                   rolls back
     * @throws \InvalidArgumentException only when the asker, who is on the ticket, asks themselves; an asker not on the
     *                                   ticket who asks themselves gets AskedPersonNotOnTicket
     * @throws \LogicException           when no transaction is open or no tenant is set, before any query
     */
    public function pauseForQuestion(TicketId $ticketId, UserId $askedUserId, UserId $askerId): void;

    /**
     * Ends the question pause, whether the asked person answered or the asker withdrew the question, so the turn goes
     * back to the step's normal owner.
     *
     * @throws TicketNotFound         when the organization has no ticket with the id
     * @throws TicketChangedMeanwhile when another request saved the ticket after this request read it; the caller rolls
     *                                back
     * @throws \LogicException        when the ticket waits for no answer, or no transaction is open or no tenant is set
     */
    public function endQuestionPause(TicketId $ticketId): void;
}

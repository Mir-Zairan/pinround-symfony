<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The question pause of a ticket, for Conversation's AskQuestion and Answer. Each method locks the ticket's row in the
 * organization of TenantContext until the caller's transaction ends, so two requests cannot both pass its check, and
 * saves the ticket at once. The step stays as it is. The caller checks who may ask and answer, and refreshes the
 * ticket's turn after the call in the same transaction.
 */
interface TicketPauses
{
    /**
     * Pauses the ticket until the asked person answers the asker's question.
     *
     * @throws TicketNotFound            when the organization has no ticket with the id
     * @throws TicketAlreadyPaused       when another question already pauses the ticket; nothing is saved
     * @throws TicketChangedMeanwhile    when another request saved the ticket after this request read it; the caller
     *                                   rolls back
     * @throws \InvalidArgumentException when the asked person is the asker
     * @throws \LogicException           when no transaction is open or no tenant is set, before any query
     */
    public function pauseForQuestion(TicketId $ticketId, UserId $askedUserId, UserId $askerId): void;

    /**
     * Ends the question pause, so the turn goes back to the step's normal owner.
     *
     * @throws TicketNotFound         when the organization has no ticket with the id
     * @throws TicketChangedMeanwhile when another request saved the ticket after this request read it; the caller rolls
     *                                back
     * @throws \LogicException        when the ticket waits for no answer, or no transaction is open or no tenant is set
     */
    public function resumeAfterAnswer(TicketId $ticketId): void;
}

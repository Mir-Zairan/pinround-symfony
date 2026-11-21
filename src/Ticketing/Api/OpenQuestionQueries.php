<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * The open questions of a page of tickets. Conversation implements it, so a page outside Conversation quotes the
 * question without depending on it. It runs in the caller's open transaction under the organization as tenant, under
 * row-level security, and does not check visibility: pass only tickets the asked person may see.
 */
interface OpenQuestionQueries
{
    public const int MAX_TICKET_IDS = 100;

    /**
     * One query, none for an empty $ticketIds. A ticket has at most one open question; a ticket whose question is
     * answered, removed or to someone else, or that is of another organization, is left out.
     *
     * @param list<TicketId> $ticketIds at most MAX_TICKET_IDS
     *
     * @return array<string, OpenQuestion> by ticket id string
     *
     * @throws \InvalidArgumentException when $ticketIds holds more than MAX_TICKET_IDS ids, before any query
     * @throws \LogicException           when no transaction is open or no tenant is set, before any query
     */
    public function openQuestionsTo(UserId $askedId, array $ticketIds): array;
}

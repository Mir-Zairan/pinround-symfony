<?php

declare(strict_types=1);

namespace App\Insight\Api;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * How many reviews and tests wait for people, counted from ins_ticket_turn of the organization of TenantContext under
 * row-level security, so it needs an open transaction whose tenant is that organization. The counts ignore ticket
 * visibility: they feed the reviewer and tester choice of the turn rules and are never shown.
 */
interface TeamLoadQueries
{
    public const string KIND_REVIEW = 'review';
    public const string KIND_TEST = 'test';

    /**
     * One query; an empty $userIds runs none. The ticket's own row is left out, so a ticket in review does not count
     * against its own reviewer.
     *
     * @param list<UserId> $userIds
     *
     * @return array<string, array<string, int>> tickets waiting by user id string and kind (KIND_REVIEW or KIND_TEST); a
     *                                           person or kind with none is left out
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function waitingTurnsOf(array $userIds, TicketId $excludedTicketId): array;

    /**
     * The same counts, leaving out the ticket's own row, and that row's turn, in one query that runs even for an empty
     * $userIds. The saved turn is what Home shows, so a page that names whose turn it is names the same person.
     *
     * @param list<UserId> $userIds
     *
     * @throws \LogicException when no transaction is open or no tenant is set, before any query
     */
    public function loadsAndSavedTurnOf(array $userIds, TicketId $ticketId): TeamLoads;
}

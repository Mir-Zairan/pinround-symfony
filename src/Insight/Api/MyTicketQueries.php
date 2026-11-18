<?php

declare(strict_types=1);

namespace App\Insight\Api;

/**
 * The open tickets one viewer works on, for the MCP tool list_my_tickets. It reads ins_ticket_turn of the organization
 * of TenantContext under row-level security, so it needs an open transaction whose tenant is the viewer's organization.
 */
interface MyTicketQueries
{
    /** The most tickets myTicketsOf() returns in one call. */
    public const int MAX_TICKETS = 50;

    /**
     * The open tickets whose turn is the viewer's or whose assignee is the viewer, only those the viewer may see
     * (ADR-0013), the most recently changed first, then by ticket id, highest first. A done ticket has no turn row, so
     * it is never among them. One query on ins_ticket_turn joined to Ticketing's view tkt_ticket_list_v.
     *
     * @param int $limit 1 to MAX_TICKETS
     *
     * @throws \InvalidArgumentException when the limit is outside 1 to MAX_TICKETS, before any query
     * @throws \LogicException           when no transaction is open, no tenant is set, or the viewer is of another
     *                                   organization than the tenant, before any query
     */
    public function myTicketsOf(TicketViewer $viewer, int $limit): MyTickets;
}

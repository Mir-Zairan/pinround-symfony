<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TicketId;

/**
 * The tickets list, one ticket's page and which tickets a viewer may see, for those pages and other modules. It reads the organization of TenantContext under row-level
 * security, so it needs an open transaction whose tenant is the viewer's organization.
 */
interface TicketQueries
{
    /** The most ticket ids visibleTicketIdsOf() takes in one call. */
    public const int MAX_CHECKED_TICKET_IDS = 200;

    /**
     * Which of these tickets the viewer may see (ADR-0013), for a module that keeps ticket ids and must not show a
     * ticket hidden from its reader. One query on tkt_ticket under the tenant, through TicketVisibilitySql; none for an
     * empty list.
     *
     * @param list<TicketId> $ticketIds at most MAX_CHECKED_TICKET_IDS, repeats included; a repeated id counts once
     *
     * @return list<TicketId> the visible ones, each once, in the order first given; an unknown id, another
     *                        organization's and a hidden one are left out
     *
     * @throws \InvalidArgumentException when more than MAX_CHECKED_TICKET_IDS ids are given, before any query
     * @throws \LogicException           when no transaction is open, no tenant is set, or the viewer is of another
     *                                   organization than the tenant, before any query
     */
    public function visibleTicketIdsOf(TicketVisibility $viewer, array $ticketIds): array;

    /**
     * Four queries at most whatever the number of steps: the organization's steps through Workflow's StepQueries in
     * one (none while its cache holds them), the rows of every step in one, the turn users of the shown rows in one,
     * and the names of their assignees and turn users in one. Only the tickets the filter's viewer may see are read or
     * counted (ADR-0013).
     *
     * Groups come in list order (Waiting, Checking, Doing, To do, then Done when the filter shows it), steps in a
     * group as the team page orders them, archived steps included. A step with no ticket the viewer may see is left
     * out, and so is a group with no such step. Each step holds its first TicketListStep::PAGE_SIZE rows by priority,
     * then most recently updated, then id. With the filter's search text, only its best 1,000 matches among the
     * filter's project and shown steps are read and counted, each step in search order: the exact key first, then the
     * best match, then id. When more match, every step's count is marked capped (TicketListStep::isCountCapped). The
     * search runs inside the rows' query, so the query count stays the same.
     *
     * @param Cursor|null $cursor null for the first page of every step; a step's next cursor for its next page, which
     *                            returns only that step's group and that step; it has a match position exactly when
     *                            the filter has a search text
     *
     * @return list<TicketListGroup>
     *
     * @throws \LogicException           when no transaction is open, no tenant is set, or the filter's viewer is of
     *                                   another organization than the tenant, before any query
     * @throws \InvalidArgumentException when the cursor has a match position and the filter no search text, or the
     *                                   other way round, before any query
     */
    public function list(ListFilter $filter, ?Cursor $cursor = null): array;

    /**
     * The ticket with that key, only when the viewer may see it (ADR-0013): a ticket hidden from them answers null,
     * exactly as an unknown key does, so a caller never learns that it exists. Its parent's key is there only when the
     * viewer may see the parent too. A page still decides TicketPermission VIEW on the visibility facts, as a second
     * guard. Two queries when the team's people are cached: the ticket with its project, its parent's key, its current
     * summary and the count of the summaries that one replaced in one, and the names of everyone the page shows in one
     * batch (reporter, assignee, reviewer, tester, pending assignee, waiting-on, the team's lead, developers and
     * testers, and the current summary's author). The team's lead and people come from Organization's
     * team people cache, two more on a miss (none for a ticket no team owns; a person Identity does not know is left
     * out); its step through Workflow's StepQueries::listedSteps and the team's steps add queries only while their
     * cache is cold. The
     * view carries the ticket's turn facts, so the turn needs no second read of the ticket. A key that cannot
     * be a ticket key (not PROJECT-number, a number from 1 up with no leading zero, case as stored) runs none.
     *
     * @param TicketVisibility $viewer whose ticket visibility predicate the ticket and its parent are read under
     *
     * @return TicketPageView|null null when the tenant has no ticket with that key the viewer may see, or the key
     *                             cannot be one
     *
     * @throws \LogicException when no transaction is open, no tenant is set, or the viewer is of another organization
     *                         than the tenant, before any query; when the ticket's step is not one of the
     *                         organization's; when its team has no lead or member Identity knows or is not the
     *                         organization's, or Identity does not know the team's lead
     */
    public function pageOf(string $key, TicketVisibility $viewer): ?TicketPageView;

    /**
     * Where the ticket with that key sits in the list the filter gives, in exactly list()'s order and under the same
     * visibility and filters, in one query (none while Workflow's StepQueries caches the steps, plus that one when
     * cold). Without a search it never sorts the whole organization: each step counts at most
     * TicketListStep::COUNT_CAP tickets as list() does, and the neighbours are read by keyset over the list's index,
     * crossing into the nearest earlier or later step that holds a ticket. With a search, only the matches list()
     * would read are ranked.
     *
     * @param ListFilter $filter the list the ticket is looked for in; its viewer's predicate decides the ticket, its
     *                           neighbours and the count
     *
     * @return TicketPosition|null null when the ticket is not in that list: an unknown key, a ticket the viewer may not
     *                             see, one the filter leaves out, a Done ticket of a list without Done, a key that
     *                             cannot be one (no query), or a match past the search's cap
     *
     * @throws \LogicException when no transaction is open, no tenant is set, or the filter's viewer is of another
     *                         organization than the tenant, before any query
     */
    public function positionOf(string $key, ListFilter $filter): ?TicketPosition;
}

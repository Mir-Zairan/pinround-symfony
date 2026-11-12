<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * The tickets list, for the list page and other modules. It reads the organization of TenantContext under row-level
 * security, so it needs an open transaction whose tenant is the viewer's organization.
 */
interface TicketQueries
{
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
}

<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\UserId;

/**
 * How many open tickets each person is the assignee of, as one viewer may count them (ADR-0013), for the person
 * picker. It reads the organization of TenantContext under row-level security, so it needs an open transaction whose
 * tenant is the viewer's organization.
 */
interface AssigneeTicketCounts
{
    /**
     * Open is a step outside the Done group. A person's count is the number of rows the tickets list without "Show
     * done" shows filtered by them and by the same project (ListFilter's projectId; every project when null), before
     * any search: the picker's search narrows people, never tickets. Only the tickets the viewer may see are counted.
     * One query for any number of people, none for no person; the organization's steps come from Workflow's
     * StepQueries (one query when its cache is cold).
     *
     * @param list<UserId> $assigneeIds
     * @param string|null  $projectId   a project's canonical uuid, as in ListFilter; null for every project
     *
     * @return array<string, int> by user id, every given person included, 0 for one with no such ticket
     *
     * @throws \InvalidArgumentException when the project id is not a canonical uuid, before any query
     * @throws \LogicException           when no transaction is open, no tenant is set, or the viewer is of another
     *                                   organization than the tenant, before any query
     */
    public function openTicketsAssignedTo(TicketVisibility $viewer, array $assigneeIds, ?string $projectId): array;
}

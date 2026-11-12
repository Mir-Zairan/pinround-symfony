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
     * Open is a step outside the Done group, as the tickets list without "Show done" shows them, so a person's count is
     * the number of rows the list shows filtered by them. Only the tickets the viewer may see are counted. One query for
     * any number of people, none for no person; the organization's steps come from Workflow's StepQueries (one query
     * when its cache is cold).
     *
     * @param list<UserId> $assigneeIds
     *
     * @return array<string, int> by user id, every given person included, 0 for one with no such ticket
     *
     * @throws \LogicException when no transaction is open, no tenant is set, or the viewer is of another organization
     *                         than the tenant, before any query
     */
    public function openTicketsAssignedTo(TicketVisibility $viewer, array $assigneeIds): array;
}

<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Domain\ProjectId;

/**
 * How many open tickets each project has, in two reads that must not be swapped: the guard count, which says whether
 * a project may be unlinked from its team, and the count a viewer is shown, which leaves out what they may not see.
 */
interface OpenTicketCounts
{
    /**
     * The guard count: every open ticket of the tenant, whoever may see it, so a project is not unlinked while it has a
     * ticket the actor cannot see. Never show it to anyone; show openTicketsVisibleTo instead.
     *
     * Reads the tenant's tickets in the caller's transaction, all projects at once, so a list of projects costs one
     * query. A project of another organization, or no project, counts 0; no projects runs no query.
     *
     * @return array<string, int> the open tickets by project id, one entry for each given project
     *
     * @throws \LogicException when projects are given and no transaction is open or no tenant is set
     */
    public function openTicketsIn(ProjectId ...$projectIds): array;

    /**
     * The count a viewer is shown: only the open tickets $viewer may see (ADR-0013), in one query for all projects,
     * otherwise like openTicketsIn. A viewer of another organization than the tenant counts 0 everywhere.
     *
     * @return array<string, int> the open tickets the viewer sees by project id, one entry for each given project
     *
     * @throws \LogicException when projects are given and no transaction is open or no tenant is set
     */
    public function openTicketsVisibleTo(TicketVisibility $viewer, ProjectId ...$projectIds): array;
}

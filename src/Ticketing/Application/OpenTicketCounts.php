<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\Ticketing\Domain\ProjectId;

/**
 * How many open tickets each project has; a project is not unlinked from its team while it has any.
 */
interface OpenTicketCounts
{
    /**
     * Reads the tenant's tickets in the caller's transaction, all projects at once, so a list of projects costs one
     * query. A project of another organization, or no project, counts 0; no projects runs no query.
     *
     * @return array<string, int> the open tickets by project id, one entry for each given project
     *
     * @throws \LogicException when projects are given and no transaction is open or no tenant is set
     */
    public function openTicketsIn(ProjectId ...$projectIds): array;
}

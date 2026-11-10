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
     * Reads under row-level security in the caller's transaction, all projects at once, so a list of projects costs
     * one query. A project of another organization, or no project, counts 0.
     *
     * @return array<string, int> the open tickets by project id, one entry for each given project
     */
    public function openTicketsIn(ProjectId ...$projectIds): array;
}

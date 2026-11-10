<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TeamId;

/**
 * The projects of the current organization, for other modules. Each list costs one query on tkt_project and, when it
 * is not empty, one batch read of the open ticket counts. They read the organization of TenantContext under row-level
 * security, so they need an open transaction whose tenant is that organization; under any other tenant they find
 * nothing.
 */
interface ProjectQueries
{
    /**
     * @return list<ProjectSummary> the projects the team owns, by key; empty when it owns none or is unknown or of
     *                              another organization
     *
     * @throws \LogicException when no tenant is set, before any query
     */
    public function projectsOf(TeamId $teamId): array;

    /**
     * @return list<ProjectSummary> the organization's projects that no team owns, by key; empty when there are none
     *
     * @throws \LogicException when no tenant is set, before any query
     */
    public function unlinkedProjects(): array;
}

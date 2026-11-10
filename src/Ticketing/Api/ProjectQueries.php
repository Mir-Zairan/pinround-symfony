<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TeamId;

/**
 * The projects of the current organization, for other modules. Each list costs one query on tkt_project and, when it
 * is not empty, one batch read of the open ticket counts. They read the organization of TenantContext under row-level
 * security, so they need an open transaction whose tenant is that organization; under any other tenant they find
 * nothing. unlinkedProjectChoices is the exception: one query and no counts.
 */
interface ProjectQueries
{
    /** How many unlinked projects a picker offers; a larger organization gets the first ones by key. */
    public const int CHOICE_LIMIT = 200;

    /**
     * @return list<ProjectSummary> the projects the team owns, by key; empty when it owns none or is unknown or of
     *                              another organization
     *
     * @throws \LogicException when no tenant is set, before any query
     */
    public function projectsOf(TeamId $teamId): array;

    /**
     * The organization's projects that no team owns, by key, for a picker: one query on tkt_project, no ticket counts.
     *
     * @param int $limit how many projects at most, from 1; CHOICE_LIMIT for a picker
     *
     * @return list<ProjectChoice> the first $limit projects by key; empty when there are none
     *
     * @throws \InvalidArgumentException when $limit is below 1, before any query
     * @throws \LogicException           when no tenant is set, before any query
     */
    public function unlinkedProjectChoices(int $limit): array;
}

<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * The projects of the current organization, for other modules. They read the organization of TenantContext under
 * row-level security, so they need an open transaction whose tenant is that organization; under any other tenant they
 * find nothing.
 */
interface ProjectQueries
{
    /** How many unlinked projects a picker offers; a larger organization gets the first ones by key. */
    public const int CHOICE_LIMIT = 200;

    /**
     * One query on tkt_project and, when the team owns projects, the viewer's ticket visibility (TicketVisibilities::of)
     * and one batch count of the open tickets that viewer may see, for all the projects at once.
     *
     * @param UserId $viewerId whose open-ticket counts: a ticket they may not see is not counted (ADR-0013)
     *
     * @return list<ProjectSummary> the projects the team owns, by key; empty when it owns none or is unknown or of
     *                              another organization
     *
     * @throws \LogicException when no tenant is set, before any query
     */
    public function projectsOf(TeamId $teamId, UserId $viewerId): array;

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

    /**
     * The projects the given teams own, by key, for a picker: one query on tkt_project, no ticket counts, and none when
     * $teamIds is empty. A project no team owns is never among them.
     *
     * @param list<TeamId> $teamIds a team that is unknown, of another organization or owns no project adds nothing
     *
     * @return list<TeamProjectChoice>
     *
     * @throws \LogicException when $teamIds is not empty and no transaction is open or no tenant is set, before any query
     */
    public function projectChoicesOfTeams(array $teamIds): array;

    /**
     * The organization's project with that key, for the tickets list's project filter: one query on tkt_project
     * through tkt_project_organization_id_key_key, none for a key no project can have.
     *
     * @param string $key matched exactly, for example SHOP; a lowercase or malformed key finds nothing
     *
     * @return ProjectChoice|null null when the organization has no project with that key; with the team that owns it,
     *                            which decides the tickets page's rail
     *
     * @throws \LogicException when no tenant is set, before any query
     */
    public function projectByKey(string $key): ?ProjectChoice;

    /**
     * The key the project's next ticket gets, for the "When you send:" line of Report a problem, for example SHOP-12,
     * with the team that owns the project. One query; it reads without locking, so two reporters at once may both see
     * the same next key.
     *
     * @param string $projectId a project's uuid; a malformed or unknown one, or one of another organization, has none
     *                          and costs no query when malformed
     *
     * @throws \LogicException when no tenant is set, before any query
     */
    public function nextTicketKeyOf(string $projectId): ?NextTicketKey;
}

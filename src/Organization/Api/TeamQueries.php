<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;

/**
 * An organization's teams, for other modules. Every read runs under row-level security, so it needs an open
 * transaction whose tenant is the organization; under any other tenant, or none, it finds nothing.
 */
interface TeamQueries
{
    /**
     * @return list<TeamId> every team of the organization, in id order
     */
    public function teamsOf(OrganizationId $organizationId): array;

    /**
     * One query; a team id that is unknown or belongs to another organization is not returned, and an empty $teamIds
     * runs no query.
     *
     * @param list<TeamId> $teamIds
     *
     * @return list<TeamSummary> the asked teams, ordered by name (case-insensitive), then by id
     */
    public function summariesOf(OrganizationId $organizationId, array $teamIds): array;

    /**
     * One query, for a page that lists every team of the organization.
     *
     * @return list<TeamSummary> every team, ordered like summariesOf
     */
    public function allSummariesOf(OrganizationId $organizationId): array;

    /**
     * One `count(*)`, for a page that needs only how many teams there are.
     */
    public function countOf(OrganizationId $organizationId): int;

    /**
     * One query, for the team page at /teams/{slug}; null when no team of the organization has that slug, which is
     * also the answer for a team of another organization.
     */
    public function bySlug(OrganizationId $organizationId, string $slug): ?TeamSummary;

    /**
     * One query; null when the user is not in that team, or the team is unknown or belongs to another organization.
     *
     * @throws \LogicException when the stored job is not one of MemberJob's values
     */
    public function jobOf(TeamId $teamId, UserId $userId): ?MemberJob;

    /**
     * One query. Every team has exactly one lead, so null means only that the team is unknown or belongs to another
     * organization.
     */
    public function leadOf(TeamId $teamId): ?UserId;

    /**
     * One query, for an assignee picker over several teams: each team's lead and members, in user id order; viewers
     * are left out, as they cannot move tickets. Every asked team is a key, with an empty list for a team that is
     * unknown or belongs to another organization, and an empty $teamIds runs no query.
     *
     * @param list<TeamId> $teamIds
     *
     * @return array<string, list<UserId>> keyed by team id, in the order asked
     */
    public function possibleAssigneesOf(array $teamIds): array;
}

<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\SharedKernel\Domain\OrganizationId;

/**
 * The organization's people with their org roles and teams, for the admin People and roles tab. The reads run under
 * row-level security, so they need an open transaction whose tenant is the organization; under any other tenant, or
 * none, the organization has nobody.
 */
interface OrganizationPeopleQueries
{
    /** The most people one call of pageOf returns. */
    public const int MAX_LIMIT = 100;

    /**
     * One keyset page of the members, by PeoplePageCursor::nameKeyOf of the display name, then by user id, in three
     * queries whatever the page holds (one when the organization has nobody). A member whom Identity does not know is
     * left out.
     *
     * @param int                   $limit how many to return at most, 1 to MAX_LIMIT
     * @param PeoplePageCursor|null $after the place of the last person of the previous page, who may have been renamed
     *                                     or left since; null for the first page
     *
     * @return list<OrganizationPerson>
     *
     * @throws \InvalidArgumentException when $limit is below 1 or above MAX_LIMIT, before any query
     */
    public function pageOf(OrganizationId $organizationId, int $limit, ?PeoplePageCursor $after = null): array;
}

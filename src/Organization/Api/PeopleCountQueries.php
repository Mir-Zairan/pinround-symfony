<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;

/**
 * How many people an organization has, for a page that shows only the number. The read runs under row-level security,
 * so it needs an open transaction whose tenant is the organization; under any other tenant, or none, it counts 0.
 */
interface PeopleCountQueries
{
    /**
     * One query: the members, whatever their roles, plus the invitations not accepted yet that have not expired by
     * the clock's now, as InvitationQueries::pendingOf lists them. An organization holds at most one such invitation
     * per email address, so nobody invited counts twice.
     */
    public function peopleCountOf(OrganizationId $organizationId): int;
}

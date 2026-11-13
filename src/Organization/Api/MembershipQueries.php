<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Who belongs to which organization, for other modules.
 */
interface MembershipQueries
{
    /**
     * Through the resolver resolve_user_orgs, so it needs no tenant and may run before any is set (data-model.md).
     *
     * @return list<OrganizationId> in id order; empty when the user is in no organization
     */
    public function organizationsOf(UserId $userId): array;

    /**
     * Read under row-level security on every call, uncached, so it needs an open transaction whose tenant is
     * $organizationId; under any other tenant, or none, it finds nothing. A user with no membership there has no
     * roles, whatever team rows remain. The team ids come in id order, each with its slug (Roles::teamSlugOf).
     *
     * @throws \LogicException when a stored role is not one this module knows
     */
    public function rolesOf(UserId $userId, OrganizationId $organizationId): Roles;

    /**
     * One read for any number of users, under row-level security and uncached like rolesOf, so it needs an open
     * transaction whose tenant is $organizationId; under any other tenant, or none, it finds nobody. Membership alone
     * counts, so a member with no org or team role is one.
     *
     * @param list<UserId> $userIds an empty list reads nothing
     *
     * @return list<UserId> those of $userIds who are members of $organizationId, each once, in id order
     */
    public function membersAmong(OrganizationId $organizationId, array $userIds): array;
}

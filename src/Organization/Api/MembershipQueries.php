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
}

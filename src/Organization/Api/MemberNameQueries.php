<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The display names of an organization's members, for a module that labels something with who did it.
 */
interface MemberNameQueries
{
    public const int MAX_USER_IDS = 100;

    /**
     * Two queries (the memberships under row-level security, then the names from Identity); none for an empty
     * $userIds. It needs a transaction whose tenant is $organizationId; under any other tenant, or none, nobody is a
     * member. A person who is not a member of $organizationId, or whom Identity does not know, is left out.
     *
     * @param list<UserId> $userIds at most MAX_USER_IDS
     *
     * @return array<string, string> the display name, for example "Lena", keyed by user id string, in user id order
     *
     * @throws \InvalidArgumentException when $userIds holds more than MAX_USER_IDS ids, before any query
     */
    public function displayNamesOf(OrganizationId $organizationId, array $userIds): array;
}

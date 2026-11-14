<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The email addresses of an organization's members, for a module that writes to them, such as Notification's digest.
 */
interface MemberEmailQueries
{
    public const int MAX_USER_IDS = 100;

    /**
     * Two queries (the memberships under row-level security, then the addresses from Identity); none for an empty
     * $userIds. It needs a transaction whose tenant is $organizationId; under any other tenant, or none, nobody is a
     * member. A person who is not a member of $organizationId, whom Identity does not know, or who is blocked is left
     * out.
     *
     * @param list<UserId> $userIds at most MAX_USER_IDS
     *
     * @return array<string, string> the address, trimmed and in lowercase, keyed by user id string, in user id order
     *
     * @throws \InvalidArgumentException when $userIds holds more than MAX_USER_IDS ids, before any query
     */
    public function emailsOf(OrganizationId $organizationId, array $userIds): array;
}

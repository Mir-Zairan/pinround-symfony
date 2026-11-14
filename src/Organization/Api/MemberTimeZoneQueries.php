<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The time zones of an organization's members, for a module that times something on the person's own clock.
 */
interface MemberTimeZoneQueries
{
    public const int MAX_USER_IDS = 100;

    /**
     * Two queries (the memberships under row-level security, then the zones from Identity); none for an empty
     * $userIds. It needs a transaction whose tenant is $organizationId; under any other tenant, or none, nobody is a
     * member. A person who is not a member of $organizationId, or whom Identity does not know, is left out; a member
     * whose saved zone PHP does not know gets UTC.
     *
     * @param list<UserId> $userIds at most MAX_USER_IDS
     *
     * @return array<string, \DateTimeZone> keyed by user id string, in user id order
     *
     * @throws \InvalidArgumentException when $userIds holds more than MAX_USER_IDS ids, before any query
     */
    public function timeZonesOf(OrganizationId $organizationId, array $userIds): array;
}

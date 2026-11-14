<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Identity;

use App\Identity\Api\UserQueries;
use App\Organization\Api\MemberEmailQueries;
use App\Organization\Api\MembershipQueries;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Keeps the members first, so UserQueries::emailsOf, which is not limited to the tenant, only reads people of it.
 */
final readonly class IdentityMemberEmailQueries implements MemberEmailQueries
{
    public function __construct(private MembershipQueries $membershipQueries, private UserQueries $userQueries)
    {
    }

    public function emailsOf(OrganizationId $organizationId, array $userIds): array
    {
        if (\count($userIds) > self::MAX_USER_IDS) {
            throw new \InvalidArgumentException(\sprintf('Ask for the email addresses of at most %d people at once.', self::MAX_USER_IDS));
        }

        $memberIds = $this->membershipQueries->membersAmong($organizationId, $userIds);
        if ([] === $memberIds) {
            return [];
        }

        return $this->userQueries->emailsOf($memberIds);
    }
}

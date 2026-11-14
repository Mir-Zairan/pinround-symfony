<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Identity;

use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\Organization\Api\MemberNameQueries;
use App\Organization\Api\MembershipQueries;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Keeps the members first, so UserQueries::byIds, which is not limited to the tenant, only names people of it.
 */
final readonly class IdentityMemberNameQueries implements MemberNameQueries
{
    public function __construct(private MembershipQueries $membershipQueries, private UserQueries $userQueries)
    {
    }

    public function displayNamesOf(OrganizationId $organizationId, array $userIds): array
    {
        if (\count($userIds) > self::MAX_USER_IDS) {
            throw new \InvalidArgumentException(\sprintf('Ask for the names of at most %d people at once.', self::MAX_USER_IDS));
        }

        $memberIds = $this->membershipQueries->membersAmong($organizationId, $userIds);
        if ([] === $memberIds) {
            return [];
        }

        return array_map(static fn (UserView $user): string => $user->displayName, $this->userQueries->byIds($memberIds));
    }
}

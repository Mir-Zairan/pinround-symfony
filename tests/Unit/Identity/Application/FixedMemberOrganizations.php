<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Api\MemberOrganization;
use App\Identity\Api\MemberOrganizations;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Each person's current organizations, given up front, in id order as NamedMemberOrganizations gives them.
 */
final readonly class FixedMemberOrganizations implements MemberOrganizations
{
    /**
     * @param array<string, list<MemberOrganization>> $organizationsByUserId
     */
    public function __construct(private array $organizationsByUserId)
    {
    }

    public function of(UserId $userId): array
    {
        $organizations = $this->organizationsByUserId[$userId->toString()] ?? [];
        usort($organizations, static fn (MemberOrganization $left, MemberOrganization $right): int => strcmp($left->id->toString(), $right->id->toString()));

        return $organizations;
    }

    public function isMember(UserId $userId, OrganizationId $organizationId): bool
    {
        foreach ($this->organizationsByUserId[$userId->toString()] ?? [] as $organization) {
            if ($organization->id->equals($organizationId)) {
                return true;
            }
        }

        return false;
    }
}

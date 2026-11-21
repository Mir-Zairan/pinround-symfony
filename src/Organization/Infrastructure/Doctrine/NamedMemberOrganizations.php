<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Identity\Api\MemberOrganization;
use App\Identity\Api\MemberOrganizations;
use App\Organization\Api\MembershipQueries;
use App\Organization\Application\OrganizationNames;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Identity's MemberOrganizations from the memberships (resolve_user_orgs, which lists only current org_membership
 * rows) and the names, as the organization picker reads them.
 */
final readonly class NamedMemberOrganizations implements MemberOrganizations
{
    public function __construct(private MembershipQueries $membershipQueries, private OrganizationNames $organizationNames)
    {
    }

    public function of(UserId $userId): array
    {
        $memberOrganizations = [];
        foreach ($this->organizationNames->namesOf($this->membershipQueries->organizationsOf($userId)) as $organizationId => $name) {
            $memberOrganizations[] = new MemberOrganization(new OrganizationId($organizationId), $name);
        }

        return $memberOrganizations;
    }
}

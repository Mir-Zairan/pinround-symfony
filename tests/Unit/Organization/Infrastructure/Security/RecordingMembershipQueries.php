<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Security;

use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Gives the roles it was set up with and records every rolesOf call; a user it was not given has no roles.
 */
final class RecordingMembershipQueries implements MembershipQueries
{
    /** @var list<string> each rolesOf call as "<user id> <organization id>" */
    public private(set) array $rolesOfCalls = [];

    /**
     * @param array<string, Roles> $rolesByUserAndOrganization keyed by "<user id> <organization id>"
     */
    public function __construct(private readonly array $rolesByUserAndOrganization)
    {
    }

    public function organizationsOf(UserId $userId): array
    {
        throw new \LogicException('Only rolesOf is expected.');
    }

    public function rolesOf(UserId $userId, OrganizationId $organizationId): Roles
    {
        $key = $userId->toString().' '.$organizationId->toString();
        $this->rolesOfCalls[] = $key;

        return $this->rolesByUserAndOrganization[$key] ?? Roles::none();
    }

    public function membersAmong(OrganizationId $organizationId, array $userIds): array
    {
        throw new \LogicException('Only rolesOf is expected.');
    }
}

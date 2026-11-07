<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Api\Event\OrgRolesChanged;
use App\Organization\Domain\Memberships;
use App\Organization\Domain\NotAnOrganizationMember;
use App\Organization\Domain\OrganizationNeedsAnAdmin;
use App\Organization\Domain\OrgRoles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Gives a member exactly these org roles (FR-ORG-03). The organization keeps at least one admin, also when two admins
 * remove each other's Admin role at the same time: the admins' rows stay locked until the caller's transaction ends.
 * Records OrgRolesChanged when the roles change.
 */
final readonly class SetOrgRoles
{
    public function __construct(private Memberships $memberships, private OrganizationEvents $events)
    {
    }

    /**
     * @param ?UserId $actingUser null when the system changes the roles, as the demo seed does
     *
     * @throws OrganizationNeedsAnAdmin when it would remove the last admin's Admin role; nothing is saved
     * @throws NotAnOrganizationMember  when the user is not a member of the organization
     * @throws \LogicException          when no transaction is open
     */
    public function __invoke(OrganizationId $organizationId, UserId $userId, OrgRoles $orgRoles, ?UserId $actingUser, \DateTimeImmutable $now): void
    {
        // Before loading the membership: the lock reloads the admins' rows and would drop changes made before it.
        $admins = $this->memberships->lockAdminsOf($organizationId);
        $membership = $this->memberships->of($organizationId, $userId)
            ?? throw new NotAnOrganizationMember('This person is not a member of the organization.');
        $orgRolesBefore = $membership->orgRoles();
        if ($orgRolesBefore->equals($orgRoles)) {
            return;
        }

        $membership->changeRoles($orgRoles, $admins);
        $this->memberships->save($membership);

        $this->events->publish(new OrgRolesChanged(
            $organizationId->toString(),
            $userId->toString(),
            $orgRolesBefore->values(),
            $orgRoles->values(),
            $actingUser?->toString(),
            $now,
        ));
    }
}

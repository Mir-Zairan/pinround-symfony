<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Api\Event\MemberJoined;
use App\Organization\Domain\AlreadyAnOrganizationMember;
use App\Organization\Domain\Membership;
use App\Organization\Domain\Memberships;
use App\Organization\Domain\OrgRoles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Makes a user a member of the organization with these org roles, for the demo seed now and accepted invitations
 * later. Records MemberJoined. Its writes share the caller's transaction, begun under $organizationId.
 */
final readonly class AddMember
{
    public function __construct(private Memberships $memberships, private OrganizationEvents $events)
    {
    }

    /**
     * @param ?UserId $actingUser null when the system adds the member, as the demo seed does
     *
     * @throws AlreadyAnOrganizationMember before anything is saved; two concurrent adds of the same user fail on the
     *                                     membership key instead
     */
    public function __invoke(OrganizationId $organizationId, UserId $userId, OrgRoles $orgRoles, ?UserId $actingUser, \DateTimeImmutable $now): void
    {
        if (null !== $this->memberships->of($organizationId, $userId)) {
            throw new AlreadyAnOrganizationMember('This person is in the organization already.');
        }

        $this->memberships->add(new Membership($organizationId, $userId, $orgRoles, $now));

        $this->events->publish(new MemberJoined($organizationId->toString(), $userId->toString(), $orgRoles->values(), $actingUser?->toString(), $now));
    }
}

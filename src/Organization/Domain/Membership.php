<?php

declare(strict_types=1);

namespace App\Organization\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\UserId;

/**
 * A user's place in one organization, with their org roles. Every person in an organization has one.
 */
final class Membership implements TenantOwned
{
    // Kept as strings and wrapped on the way out, like Identity's User: the mapping stays free of value-object types.
    private readonly string $organizationId;
    private readonly string $userId;
    /** @var list<string> */
    private array $orgRoles;
    // Not readonly: Memberships::lockAdminsOf refreshes loaded memberships, and Doctrine then sets every field again,
    // which a readonly property refuses for a new but equal date object.
    private \DateTimeImmutable $joinedAt;

    public function __construct(OrganizationId $organizationId, UserId $userId, OrgRoles $orgRoles, \DateTimeImmutable $joinedAt)
    {
        $this->organizationId = $organizationId->toString();
        $this->userId = $userId->toString();
        $this->orgRoles = $orgRoles->values();
        $this->joinedAt = $joinedAt;
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function userId(): UserId
    {
        return new UserId($this->userId);
    }

    public function orgRoles(): OrgRoles
    {
        return OrgRoles::fromValues($this->orgRoles);
    }

    public function joinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    /**
     * Refuses to leave the organization without an admin. Pass the admin memberships from Memberships::lockAdminsOf,
     * so a concurrent change of another admin waits until this one is saved; this membership may be among them.
     *
     * @param list<Membership> $adminsOfTheOrganization
     *
     * @throws OrganizationNeedsAnAdmin when this member is the only admin and $orgRoles has no Admin
     */
    public function changeRoles(OrgRoles $orgRoles, array $adminsOfTheOrganization): void
    {
        if ($this->orgRoles()->isAdmin() && !$orgRoles->isAdmin() && !$this->hasAnotherAdminAmong($adminsOfTheOrganization)) {
            throw new OrganizationNeedsAnAdmin('An organization needs at least one admin.');
        }

        $this->orgRoles = $orgRoles->values();
    }

    /**
     * @param list<Membership> $memberships
     */
    private function hasAnotherAdminAmong(array $memberships): bool
    {
        foreach ($memberships as $membership) {
            if ($membership->organizationId === $this->organizationId
                && $membership->userId !== $this->userId
                && $membership->orgRoles()->isAdmin()) {
                return true;
            }
        }

        return false;
    }
}

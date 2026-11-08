<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Who is acting, in which organization, with which org and team roles: what a voter decides on (ADR-0013).
 */
final readonly class ActorContext
{
    public function __construct(private UserId $userId, private OrganizationId $organizationId, private Roles $roles)
    {
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function organizationId(): OrganizationId
    {
        return $this->organizationId;
    }

    public function roles(): Roles
    {
        return $this->roles;
    }
}

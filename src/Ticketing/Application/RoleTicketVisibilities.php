<?php

declare(strict_types=1);

namespace App\Ticketing\Application;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\Organization\Api\SettingsQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;

/**
 * The one place that builds a viewer's ticket visibility predicate (ADR-0013): their organization, their teams of any
 * team role, whether they are an admin, and the organization's triager.
 */
final readonly class RoleTicketVisibilities implements TicketVisibilities
{
    public function __construct(private MembershipQueries $membershipQueries, private SettingsQueries $settingsQueries)
    {
    }

    public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
    {
        return $this->build($organizationId, $viewerId, $this->membershipQueries->rolesOf($viewerId, $organizationId));
    }

    /**
     * Like of() for the acting user, with the roles ActorContext already holds, so it reads only the triager; it needs
     * the same open transaction under the actor's organization.
     *
     * @throws \InvalidArgumentException when the stored triager is not a user id
     */
    public function ofActor(ActorContext $actor): TicketVisibility
    {
        return $this->build($actor->organizationId(), $actor->userId(), $actor->roles());
    }

    private function build(OrganizationId $organizationId, UserId $viewerId, Roles $roles): TicketVisibility
    {
        return TicketVisibility::for(
            $organizationId,
            $viewerId,
            $roles->teamIds(),
            $roles->isAdmin(),
            $this->settingsQueries->triagerOf($organizationId),
        );
    }
}

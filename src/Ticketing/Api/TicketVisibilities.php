<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The ticket visibility predicate of any viewer, for every module that shows, counts or sends tickets (ADR-0013).
 */
interface TicketVisibilities
{
    /**
     * Reads the viewer's roles and the organization's triager under row-level security, so it needs an open
     * transaction whose tenant is $organizationId; under any other tenant, or none, the viewer has no team and is
     * neither admin nor triager, so they see only the organization's tickets for everyone and the ones they are on.
     * A user who is not a member of the organization gets the same.
     *
     * @throws \LogicException           when a stored role is not one Organization knows
     * @throws \InvalidArgumentException when the stored triager is not a user id
     */
    public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility;
}

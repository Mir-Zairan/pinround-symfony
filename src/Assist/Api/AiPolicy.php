<?php

declare(strict_types=1);

namespace App\Assist\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The organization's AI policy for one person, for pages that show AI help and for the server's AI door. A request
 * about one ticket is decided again in the gateway, with the ticket's visibility and the laptop's state. forTokenUser
 * and for read under row-level security, uncached, so they need an open transaction whose tenant is the organization; under
 * any other tenant, or none, AI help reads as off, as does a stored setting the Organization module does not know.
 */
interface AiPolicy
{
    /**
     * For a page: the person and organization of the request, with the roles Organization's CurrentActor already holds
     * for it, so the roles are read at most once per request.
     *
     * @param mixed $tokenUser what the security token's getUser() returned; AI off when it is not a user logged in
     *                         to an organization
     */
    public function forTokenUser(mixed $tokenUser): PolicyView;

    /**
     * Outside a request, as in a worker. Reads the viewer's roles only when the organization allows AI help for leads
     * only.
     */
    public function for(UserId $viewerId, OrganizationId $organizationId): PolicyView;

    /**
     * The line that keeps the server's AI from a ticket for the person $policy is about, in the order the server's AI
     * door decides it before a summary is queued, with the laptop not ready: $policy's own refusal, then "laptop only",
     * which the server never runs, then a team-only ticket the admin keeps from cloud AI. Reads nothing.
     *
     * @param bool $isTicketTeamOnly the ticket's visibility is team (team-only)
     *
     * @return string|null null when the server's AI may run for the ticket
     */
    public function serverTicketRefusalOf(PolicyView $policy, bool $isTicketTeamOnly): ?string;
}

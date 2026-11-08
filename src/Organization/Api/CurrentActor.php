<?php

declare(strict_types=1);

namespace App\Organization\Api;

/**
 * The actor of the current request, for voters in any module (ADR-0013). The ActorContext is built once per request
 * for each user and organization, from one MembershipQueries::rolesOf call, and reused for the rest of that request;
 * the kernel resets it between requests, so in worker mode the next request reads the roles again (ADR-0008).
 *
 * Ask it inside the request transaction: rolesOf reads under row-level security and, outside one, finds no roles, so
 * the voter denies.
 */
interface CurrentActor
{
    /**
     * @param mixed $tokenUser what the security token's getUser() returned
     *
     * @return ActorContext|null null when it is not a user Identity logged in, or the request has no organization
     *                           (tenant); a voter denies then
     */
    public function actorFor(mixed $tokenUser): ?ActorContext;
}

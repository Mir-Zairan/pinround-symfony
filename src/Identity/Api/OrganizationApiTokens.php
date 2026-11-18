<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The personal access tokens of the tenant's organization, for its Admins (P09.T1, ADR-0021). It does not check who
 * asks: the caller's voter has decided that the person is an Admin of the tenant.
 */
interface OrganizationApiTokens
{
    /** How many tokens livePage() gives at most in one call. */
    public const int MAX_PAGE_SIZE = 100;

    /**
     * One query. The tenant's tokens that are neither revoked nor expired at $now, of every person, the soonest to
     * expire first (by expires_at, then the lower id), after $after when given.
     *
     * @param int                       $limit 1 to MAX_PAGE_SIZE
     * @param OrganizationApiToken|null $after the last token of the page before; only its expiresAt and id are read
     *
     * @return list<OrganizationApiToken> at most $limit
     *
     * @throws \InvalidArgumentException when $limit is not between 1 and MAX_PAGE_SIZE, before any query
     * @throws \LogicException           when no tenant is set
     */
    public function livePage(\DateTimeImmutable $now, int $limit, ?OrganizationApiToken $after = null): array;

    /**
     * Revokes any token of the tenant, whoever owns it, at once, and publishes ApiTokenRevoked with $adminId as the
     * actor and the owner as the user, in the caller's transaction (RequestTransaction on the web). An expired token
     * is revoked as well.
     *
     * @return bool true when this call revoked it; false when it was revoked already, as after a double click, and
     *              then nothing is published
     *
     * @throws ApiTokenNotInOrganization when $tokenId is not a UUID or no token of the tenant has it; nothing is changed
     * @throws \LogicException           when no tenant is set
     */
    public function revokeAsAdmin(UserId $adminId, string $tokenId, \DateTimeImmutable $now): bool;
}

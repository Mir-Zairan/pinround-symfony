<?php

declare(strict_types=1);

namespace App\Identity\Api;

/**
 * The OAuth connections of the tenant's organization, for its Admins (ADR-0050), as OrganizationApiTokens gives its
 * personal access tokens. livePage() does not check who asks: the caller's voter has decided that the person is an
 * Admin of the tenant. end() asks the voter itself.
 */
interface OrganizationOAuthConnections
{
    /** How many connections livePage() gives at most in one call. */
    public const int MAX_PAGE_SIZE = 100;

    /**
     * One query. The tenant's live connections of every person, the newest connected first (by connectedAt, then the
     * higher familyId), after the one whose connectedAt and familyId are $beforeConnectedAt and $beforeFamilyId when
     * both are given: the last one of the page before.
     *
     * @param int $limit 1 to MAX_PAGE_SIZE
     *
     * @return list<OAuthConnection> at most $limit
     *
     * @throws \InvalidArgumentException when $limit is not between 1 and MAX_PAGE_SIZE, only one of $beforeConnectedAt
     *                                   and $beforeFamilyId is given, or $beforeFamilyId is not a UUID; before any
     *                                   query
     * @throws \LogicException           when no tenant is set
     */
    public function livePage(\DateTimeImmutable $now, int $limit, ?\DateTimeImmutable $beforeConnectedAt = null, ?string $beforeFamilyId = null): array;

    /**
     * Ends any connection of the tenant, as EndOAuthConnection does for its person: its refresh token and its access
     * token stop at once, and ApiTokenRevoked names the request's person as the actor, in the caller's transaction.
     *
     * @return bool true when this call ended it; false when it was ended already, as after a double click
     *
     * @throws OAuthConnectionNotFound when $familyId is not a UUID or no connection of the tenant has it
     * @throws OAuthConnectionRefused  when the voter refuses the request's person
     * @throws \LogicException         when no tenant is set, or no transaction is open
     */
    public function end(string $familyId, \DateTimeImmutable $now): bool;
}

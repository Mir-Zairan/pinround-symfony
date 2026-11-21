<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Api\OAuthConnection;
use App\SharedKernel\Domain\UserId;

/**
 * The live OAuth connections of the tenant's organization, read from its refresh tokens. A connection is live while
 * the newest token of its family is neither traded, revoked nor expired at the given time.
 */
interface OAuthConnections
{
    /** How many connections liveOf() gives at most: more than a person connects in practice. */
    public const int MAX_LISTED = 50;

    /**
     * One query. The user's live connections in the tenant, the newest connected first (by connectedAt, then the higher
     * familyId).
     *
     * @return list<OAuthConnection> at most MAX_LISTED
     *
     * @throws \LogicException when no tenant is set
     */
    public function liveOf(UserId $userId, \DateTimeImmutable $at): array;

    /**
     * One query. The tenant's live connections of every person, ordered as liveOf(), after the one whose connectedAt
     * and familyId are $beforeConnectedAt and $beforeFamilyId when both are given.
     *
     * @param int $limit at least 1
     *
     * @return list<OAuthConnection> at most $limit
     *
     * @throws \InvalidArgumentException when $limit is below 1, only one of $beforeConnectedAt and $beforeFamilyId is
     *                                   given, or $beforeFamilyId is not a UUID; before any query
     * @throws \LogicException           when no tenant is set
     */
    public function livePage(\DateTimeImmutable $at, int $limit, ?\DateTimeImmutable $beforeConnectedAt = null, ?string $beforeFamilyId = null): array;
}

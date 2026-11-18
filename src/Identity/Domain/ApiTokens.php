<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Personal access tokens of the organization of the current transaction, but ownerOfLiveToken(), which runs before
 * any tenant is known.
 */
interface ApiTokens
{
    /**
     * A new id no token has yet: a UUIDv7 (ADR-0006).
     */
    public function nextIdentity(): string;

    /**
     * Saves the token at once.
     */
    public function add(ApiToken $token): void;

    /**
     * Holds, until the open transaction ends, the lock under which the user's tokens in the organization are counted and
     * one is added, so of two issues at once the second counts after the first has added its token.
     *
     * @throws \LogicException when no transaction is open, or $organizationId is not the tenant
     */
    public function lockIssuingFor(OrganizationId $organizationId, UserId $userId): void;

    /**
     * The user's tokens in the tenant that are neither revoked nor expired at $at.
     *
     * @throws \LogicException when no tenant is set
     */
    public function countLiveOf(UserId $userId, \DateTimeImmutable $at): int;

    /**
     * @return ApiToken|null null when $id is not a UUID, no token of the tenant has it, or $ownerId does not own it
     */
    public function byIdForOwner(string $id, UserId $ownerId): ?ApiToken;

    /**
     * Every token of the user in the tenant, revoked and expired ones included, newest first.
     *
     * @return list<ApiToken>
     */
    public function forUser(UserId $userId): array;

    /**
     * Revokes the token in the database at once, in one conditional statement, so of two revokes only the first
     * counts and the first revocation time stays. A token already loaded keeps its old revokedAt.
     *
     * @return bool true when this call revoked it; false when $id is not a UUID, no token of the tenant has it, or it
     *              was revoked already
     *
     * @throws \LogicException when no tenant is set
     */
    public function revoke(string $id, \DateTimeImmutable $revokedAt): bool;

    /**
     * Records a use in the database at once, but only when none is recorded or the last is more than a minute before $usedAt,
     * so a busy token writes its row at most once a minute. A token already loaded keeps its old lastUsedAt.
     *
     * @return bool true when this call wrote the use
     *
     * @throws \LogicException when no tenant is set
     */
    public function markUsed(string $id, \DateTimeImmutable $usedAt): bool;

    /**
     * Through resolve_api_token, before any tenant is known: the ids of the token with this hash when it is neither
     * revoked nor expired by the database clock.
     *
     * @param string $tokenHash ApiTokenHashing::hash() of the presented token
     *
     * @return ApiTokenOwner|null null for an unknown, revoked or expired token alike
     */
    public function ownerOfLiveToken(string $tokenHash): ?ApiTokenOwner;
}

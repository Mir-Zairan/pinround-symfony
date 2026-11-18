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
    /** How many tokens forUser() lists at most: the newest ones. */
    public const int MAX_LISTED_TOKENS = 50;

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
     * Any person's token, for an Admin of the tenant.
     *
     * @return ApiToken|null null when $id is not a UUID or no token of the tenant has it
     *
     * @throws \LogicException when no tenant is set
     */
    public function byId(string $id): ?ApiToken;

    /**
     * The tenant's tokens of every person that are neither revoked nor expired at $at, the soonest to expire first (by
     * expires_at, then the lower id), after the token whose expiresAt and id are $afterExpiresAt and $afterId when
     * both are given. A keyset page: no offset, so a page deep in the list costs what the first does.
     *
     * @param int $limit at least 1; the caller caps it
     *
     * @return list<ApiToken> at most $limit
     *
     * @throws \InvalidArgumentException when $limit is below 1, or only one of $afterExpiresAt and $afterId is given,
     *                                   or $afterId is not a UUID
     * @throws \LogicException           when no tenant is set
     */
    public function livePage(\DateTimeImmutable $at, int $limit, ?\DateTimeImmutable $afterExpiresAt = null, ?string $afterId = null): array;

    /**
     * The user's MAX_LISTED_TOKENS newest tokens in the tenant, revoked and expired ones included, newest first (by
     * created_at, then the later id). Older ones are left out, a live one too when 50 newer tokens were made since.
     *
     * @return list<ApiToken> at most MAX_LISTED_TOKENS
     */
    public function forUser(UserId $userId): array;

    /**
     * The user's tokens in the tenant that are neither revoked nor expired at $at, newest first (by created_at, then
     * the later id). IssueApiToken keeps at most 20 live per person, so the cap of MAX_LISTED_TOKENS leaves none out.
     *
     * @return list<ApiToken> at most MAX_LISTED_TOKENS
     *
     * @throws \LogicException when no tenant is set
     */
    public function liveForUser(UserId $userId, \DateTimeImmutable $at): array;

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
     * Marks up to $limit of the tenant's tokens as warned at $warnedAt, in one conditional statement: those neither
     * revoked nor warned yet that expire in [$expiresFrom, $expiresBefore) and live longer than
     * ApiToken::EXPIRY_WARNING_WINDOW_DAYS, the soonest to expire first (then the lower id). A token warned already is never returned again, so of two claims at once each token goes to one only. Writes
     * at once; a token already loaded keeps its old expiryWarnedAt.
     *
     * @param int $limit at least 1; the caller caps it
     *
     * @return list<ExpiringApiToken> the tokens this call marked, at most $limit, soonest to expire first
     *
     * @throws \InvalidArgumentException when $limit is below 1 or $expiresBefore is not after $expiresFrom
     * @throws \LogicException           when no tenant is set
     */
    public function claimExpiryWarnings(\DateTimeImmutable $expiresFrom, \DateTimeImmutable $expiresBefore, \DateTimeImmutable $warnedAt, int $limit): array;

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

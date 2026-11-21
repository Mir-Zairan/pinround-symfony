<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\OrganizationId;

/**
 * OAuth refresh tokens of the organization of the current transaction, but organizationOfToken(), which runs before
 * any tenant is known. Every write needs an open transaction under the token's organization as tenant, so it commits
 * or rolls back with the access token issued beside it.
 */
interface OAuthRefreshTokens
{
    /**
     * A new id no token has yet: a UUIDv7 (ADR-0006). Also a new family's id.
     */
    public function nextIdentity(): string;

    /**
     * Saves the token at once, in the caller's transaction.
     *
     * @throws \LogicException when no tenant is set
     */
    public function add(OAuthRefreshToken $token): void;

    /**
     * Marks the tenant's token with this hash as used at $at, in one conditional UPDATE: only when it was issued to
     * $clientId, is neither used nor revoked and expires after $at. Of two calls at once the second waits on the row
     * lock of the first and then finds it used.
     *
     * @param string $tokenHash ApiTokenHashing::hash() of the presented token
     *
     * @return OAuthRefreshToken|null the token as this call marked it; null when no token of the tenant matches, and
     *                                then nothing is changed
     *
     * @throws \LogicException when no tenant is set
     */
    public function markUsed(#[\SensitiveParameter] string $tokenHash, string $clientId, \DateTimeImmutable $at): ?OAuthRefreshToken;

    /**
     * @param string $tokenHash ApiTokenHashing::hash() of the presented token
     *
     * @return OAuthRefreshToken|null the tenant's token with this hash, used, revoked and expired ones included; null
     *                                when none has it. A token loaded earlier in the transaction keeps its old values.
     *
     * @throws \LogicException when no tenant is set
     */
    public function byHash(#[\SensitiveParameter] string $tokenHash): ?OAuthRefreshToken;

    /**
     * The tenant's token issued with this access token, used, revoked and expired ones included.
     *
     * @return OAuthRefreshToken|null null when $accessTokenId is not a UUID or no token of the tenant names it
     *
     * @throws \LogicException when no tenant is set
     */
    public function byAccessTokenId(string $accessTokenId): ?OAuthRefreshToken;

    /**
     * Revokes every token of the family not revoked yet, in one conditional UPDATE, so a second call changes nothing.
     * Tokens already loaded keep their old revokedAt.
     *
     * @return list<string> the access token ids of the tokens this call revoked that were never traded: the only access
     *                      tokens of the family that may still work, as a trade revokes the access token issued before
     *
     * @throws \LogicException when no tenant is set
     */
    public function revokeFamily(string $familyId, \DateTimeImmutable $revokedAt): array;

    /**
     * Through resolve_oauth_refresh_token, before any tenant is known: the organization of the token with this hash
     * when it is not revoked, not expired by the database clock, and its person is still a member there. A token
     * already traded is found too, so its reuse can be told from an unknown token.
     *
     * @param string $tokenHash ApiTokenHashing::hash() of the presented token
     *
     * @return OrganizationId|null null for an unknown, revoked or expired token, or one of a person who left, alike
     */
    public function organizationOfToken(#[\SensitiveParameter] string $tokenHash): ?OrganizationId;
}

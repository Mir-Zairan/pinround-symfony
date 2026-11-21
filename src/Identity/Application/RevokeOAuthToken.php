<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Api\ApiTokenNotInOrganization;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokens;
use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthClients;
use App\Identity\Domain\OAuthRefreshToken;
use App\Identity\Domain\OAuthRefreshTokens;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Token revocation for OAuth clients (RFC 7009, ADR-0050), as the extension's "Sign out" uses it. A client revokes only
 * tokens issued to it: a refresh token revokes its whole family and the access token that may still work with it; an
 * access token stops alone. A personal access token, a token of another client and an unknown token are left alone,
 * and the client is told nothing about which it was (RFC 7009 section 2.2). EndOAuthConnection ends a family the same
 * way, through endFamily().
 */
final readonly class RevokeOAuthToken
{
    private const int MAX_TOKEN_LENGTH = 200;

    public function __construct(
        private OAuthClients $clients,
        private OAuthRefreshTokens $refreshTokens,
        private ApiTokens $apiTokens,
        private ApiTokenHashing $hashing,
        private RevokeApiToken $revokeApiToken,
        private TenantContext $tenantContext,
        private Transactions $transactions,
    ) {
    }

    /**
     * Call it with no transaction open and no tenant other than none; afterwards the tenant may be the token's
     * organization.
     *
     * @param array<array-key, mixed> $parameters the request's form fields: token, client_id; token_type_hint is not
     *                                            needed, as the two kinds of token look different
     *
     * @throws OAuthTokenRefused   invalid_request for a missing or malformed field, invalid_client for an unknown
     *                             client_id; nothing is revoked
     * @throws TenantSwitchRefused when a transaction is open
     */
    public function __invoke(array $parameters, \DateTimeImmutable $now): void
    {
        $plainToken = OAuthTokenParameters::required($parameters, 'token', self::MAX_TOKEN_LENGTH);
        $clientId = OAuthTokenParameters::required($parameters, 'client_id', AuthorizeOAuthClient::MAX_CLIENT_ID_LENGTH);
        $client = $this->clients->byClientId($clientId) ?? throw new OAuthTokenRefused(OAuthTokenRefused::INVALID_CLIENT, OAuthTokenRefused::UNKNOWN_CLIENT);

        if (1 === preg_match(OAuthRefreshToken::PLAIN_SHAPE, $plainToken)) {
            $this->revokeRefreshToken($this->hashing->hash($plainToken), $client, $now);
        } elseif (1 === preg_match(ApiToken::PLAIN_SHAPE, $plainToken)) {
            $this->revokeAccessToken($this->hashing->hash($plainToken), $client, $now);
        }
    }

    /**
     * Revokes every token of the family and the access tokens of the family that may still work, when a used refresh
     * token or code came back: nobody asked, so no event names the owner as actor (RevokeApiToken::bySystem()). Runs in
     * the caller's transaction, which must be open under the family's organization as tenant.
     *
     * @throws \LogicException when no tenant is set, or no transaction is open
     */
    public function revokeFamilyBySystem(string $familyId, \DateTimeImmutable $now): void
    {
        foreach ($this->refreshTokens->revokeFamily($familyId, $now) as $accessTokenId) {
            $this->revokeApiToken->bySystem($accessTokenId, $now);
        }
    }

    /**
     * Ends $ownerId's connection: revokes every token of the family and the access token that may still work with it.
     * ApiTokenRevoked names $ownerId as the user and $actorId as the actor: the owner when their client signs out or
     * they end it on the connected apps page, an Admin who ends it for them. It does not check that $actorId may:
     * EndOAuthConnection's voter has. Runs in the caller's transaction, which must be open under the family's
     * organization as tenant.
     *
     * @return bool true when this call ended it; false when the family was revoked already, as by a second end at the
     *              same time, which waits for the first under the family's lock
     *
     * @throws \LogicException when no tenant is set, or no transaction is open
     */
    public function endFamily(string $familyId, UserId $ownerId, UserId $actorId, \DateTimeImmutable $now): bool
    {
        $accessTokenIds = $this->refreshTokens->revokeFamily($familyId, $now);
        foreach ($accessTokenIds as $accessTokenId) {
            $this->revokeAccessTokenOfFamily($ownerId, $actorId, $accessTokenId, $now);
        }

        // A family not revoked yet always has one untraded token, its newest, so this call revoked one only if it ended it.
        return [] !== $accessTokenIds;
    }

    private function revokeAccessTokenOfFamily(UserId $ownerId, UserId $actorId, string $accessTokenId, \DateTimeImmutable $now): void
    {
        try {
            if ($ownerId->equals($actorId)) {
                ($this->revokeApiToken)($ownerId, $accessTokenId, $now);
            } else {
                $this->revokeApiToken->asAdmin($actorId, $accessTokenId, $now);
            }
        } catch (ApiTokenNotFound|ApiTokenNotInOrganization) {
            // The retention job purged it: nothing is left to stop.
        }
    }

    /**
     * The client signs its person out, so ApiTokenRevoked names the owner as actor, as the tokens page does.
     */
    private function signOutFamilyOf(OAuthRefreshToken $refreshToken, \DateTimeImmutable $now): void
    {
        $this->endFamily($refreshToken->familyId(), $refreshToken->userId(), $refreshToken->userId(), $now);
    }

    private function revokeRefreshToken(string $tokenHash, OAuthClient $client, \DateTimeImmutable $now): void
    {
        $organizationId = $this->refreshTokens->organizationOfToken($tokenHash);
        if (null === $organizationId) {
            return;
        }
        $this->inTenant($organizationId, function () use ($tokenHash, $client, $now): void {
            $refreshToken = $this->refreshTokens->byHash($tokenHash);
            if (null !== $refreshToken && hash_equals($refreshToken->clientId(), $client->clientId())) {
                $this->signOutFamilyOf($refreshToken, $now);
            }
        });
    }

    private function revokeAccessToken(string $tokenHash, OAuthClient $client, \DateTimeImmutable $now): void
    {
        $owner = $this->apiTokens->ownerOfLiveToken($tokenHash);
        if (null === $owner) {
            return;
        }
        $this->inTenant($owner->organizationId, function () use ($owner, $client, $now): void {
            $refreshToken = $this->refreshTokens->byAccessTokenId($owner->tokenId);
            if (null !== $refreshToken && hash_equals($refreshToken->clientId(), $client->clientId())) {
                ($this->revokeApiToken)($owner->userId, $owner->tokenId, $now);
            }
        });
    }

    /**
     * @param \Closure(): void $revoke
     */
    private function inTenant(OrganizationId $organizationId, \Closure $revoke): void
    {
        $this->tenantContext->set($organizationId);
        $this->transactions->run($revoke);
    }
}

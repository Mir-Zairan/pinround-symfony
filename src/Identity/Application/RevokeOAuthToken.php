<?php

declare(strict_types=1);

namespace App\Identity\Application;

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

/**
 * Token revocation for OAuth clients (RFC 7009, ADR-0050), as the extension's "Sign out" uses it. A client revokes only
 * tokens issued to it: a refresh token revokes its whole family and the access token that may still work with it; an
 * access token stops alone. A personal access token, a token of another client and an unknown token are left alone,
 * and the client is told nothing about which it was (RFC 7009 section 2.2).
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
     * Revokes every token of the refresh token's family and the access tokens of the family that may still work. Runs in
     * the caller's transaction, which must be open under the token's organization as tenant.
     */
    public function revokeFamilyOf(OAuthRefreshToken $refreshToken, \DateTimeImmutable $now): void
    {
        foreach ($this->refreshTokens->revokeFamily($refreshToken->familyId(), $now) as $accessTokenId) {
            try {
                ($this->revokeApiToken)($refreshToken->userId(), $accessTokenId, $now);
            } catch (ApiTokenNotFound) {
                // The retention job purged it: nothing is left to stop.
            }
        }
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
                $this->revokeFamilyOf($refreshToken, $now);
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

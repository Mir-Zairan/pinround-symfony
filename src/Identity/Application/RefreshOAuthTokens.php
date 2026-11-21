<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthClients;
use App\Identity\Domain\OAuthRefreshToken;
use App\Identity\Domain\OAuthRefreshTokens;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Application\Transactions;

/**
 * The refresh_token grant of the token endpoint (RFC 6749 section 6, ADR-0050) with rotation: the traded refresh token
 * is marked used, the access token issued with it is revoked, and a new access token and refresh token of the same
 * family are issued, all in one transaction. A refresh token traded already that comes back is refused and revokes its
 * whole family (RFC 9700 section 4.14.2), as either the client or a thief holds a copy. Once the person has left the
 * organization, the token is not found. Nobody acts in a rotation or a reuse, so neither publishes an event naming the
 * person, as the token page's issue and revoke do.
 */
final readonly class RefreshOAuthTokens
{
    public const string UNUSABLE_REFRESH_TOKEN = 'The refresh token is unknown, used, revoked or expired. Connect again.';
    public const string SCOPE_NOT_GRANTED = 'scope asks for more than the person granted.';

    /** Every scope name, space-separated, fits with room to spare, as at authorize. */
    private const int MAX_SCOPE_LENGTH = AuthorizeOAuthClient::MAX_SCOPE_LENGTH;
    private const int MAX_REFRESH_TOKEN_LENGTH = 200;

    public function __construct(
        private OAuthClients $clients,
        private OAuthRefreshTokens $refreshTokens,
        private ApiTokenHashing $hashing,
        private IssueOAuthTokenPair $issueOAuthTokenPair,
        private RevokeOAuthToken $revokeOAuthToken,
        private RevokeApiToken $revokeApiToken,
        private TenantContext $tenantContext,
        private Transactions $transactions,
    ) {
    }

    /**
     * Call it with no transaction open and no tenant other than none; afterwards the tenant may be the token's
     * organization.
     *
     * @param array<array-key, mixed> $parameters the request's form fields: refresh_token, client_id, and scope when the
     *                                            client asks for no more than the grant's scopes
     *
     * @throws OAuthTokenRefused   invalid_request for a missing or malformed field, invalid_client for an unknown
     *                             client_id, invalid_scope for a scope the grant lacks, invalid_grant for a refresh
     *                             token that is unknown, used, revoked, expired, of another client or of a person who
     *                             left, or when the person has too many live tokens; no token is issued
     * @throws TenantSwitchRefused when a transaction is open
     */
    public function __invoke(array $parameters, \DateTimeImmutable $now): IssuedOAuthTokens
    {
        $plainToken = OAuthTokenParameters::required($parameters, 'refresh_token', self::MAX_REFRESH_TOKEN_LENGTH);
        $clientId = OAuthTokenParameters::required($parameters, 'client_id', AuthorizeOAuthClient::MAX_CLIENT_ID_LENGTH);
        $askedScope = OAuthTokenParameters::optional($parameters, 'scope', self::MAX_SCOPE_LENGTH);
        $client = $this->clients->byClientId($clientId) ?? throw new OAuthTokenRefused(OAuthTokenRefused::INVALID_CLIENT, OAuthTokenRefused::UNKNOWN_CLIENT);
        if (1 !== preg_match(OAuthRefreshToken::PLAIN_SHAPE, $plainToken)) {
            throw OAuthTokenRefused::invalidGrant(self::UNUSABLE_REFRESH_TOKEN);
        }

        $tokenHash = $this->hashing->hash($plainToken);
        $organizationId = $this->refreshTokens->organizationOfToken($tokenHash) ?? throw OAuthTokenRefused::invalidGrant(self::UNUSABLE_REFRESH_TOKEN);
        $this->tenantContext->set($organizationId);
        $issued = $this->transactions->run(fn (): ?IssuedOAuthTokens => $this->rotated($tokenHash, $client, $askedScope, $now));

        return $issued ?? throw OAuthTokenRefused::invalidGrant(self::UNUSABLE_REFRESH_TOKEN);
    }

    /**
     * @return IssuedOAuthTokens|null null when the token cannot be traded; a reused one has then revoked its family,
     *                                which commits with the transaction
     */
    private function rotated(string $tokenHash, OAuthClient $client, ?string $askedScope, \DateTimeImmutable $now): ?IssuedOAuthTokens
    {
        $traded = $this->refreshTokens->markUsed($tokenHash, $client->clientId(), $now);
        if (null === $traded) {
            $presented = $this->refreshTokens->byHash($tokenHash);
            if (null !== $presented && null !== $presented->usedAt()) {
                $this->revokeOAuthToken->revokeFamilyBySystem($presented->familyId(), $now);
            }

            return null;
        }
        // Thrown inside the transaction, so the token is not used up.
        self::refuseScopeBeyond($traded, $askedScope);
        $this->revokeApiToken->bySystem($traded->accessTokenId(), $now);

        return $this->issueOAuthTokenPair->renewal($traded, $client, $now);
    }

    /**
     * A refresh may ask for the grant's scopes or fewer (RFC 6749 section 6); the new tokens keep the grant's, and the
     * answer's scope says which.
     *
     * @throws OAuthTokenRefused invalid_scope
     */
    private static function refuseScopeBeyond(OAuthRefreshToken $traded, ?string $askedScope): void
    {
        if (null === $askedScope) {
            return;
        }
        $grantedScopeNames = array_map(static fn (ApiTokenScope $scope): string => $scope->value, $traded->scopes());
        foreach (explode(' ', $askedScope) as $scopeName) {
            if ('' !== $scopeName && !\in_array($scopeName, $grantedScopeNames, true)) {
                throw new OAuthTokenRefused(OAuthTokenRefused::INVALID_SCOPE, self::SCOPE_NOT_GRANTED);
            }
        }
    }
}

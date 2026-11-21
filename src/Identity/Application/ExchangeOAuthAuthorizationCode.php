<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\OAuthAuthorizationCode;
use App\Identity\Domain\OAuthAuthorizationCodes;
use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthClients;
use App\Identity\Domain\OAuthRedirectUri;
use App\Identity\Domain\OAuthRefreshTokens;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Application\Transactions;

/**
 * The authorization_code grant of the token endpoint (RFC 6749 section 4.1.3, RFC 7636, ADR-0050): a public client
 * trades a code for an access token and a refresh token. The code is used up first, in its own transaction, so of two
 * trades at once only one gets past it; then it must have been issued to this client_id, for exactly this redirect_uri,
 * and with the S256 challenge of this code_verifier. A code that fails any of these stays used, and no token is issued.
 */
final readonly class ExchangeOAuthAuthorizationCode
{
    public const string UNUSABLE_CODE = 'The code is unknown, used or expired. Start connecting again.';
    public const string OTHER_CLIENT = 'The code was issued to another client.';
    public const string OTHER_REDIRECT_URI = 'redirect_uri is not the one of the authorize request.';
    public const string WRONG_VERIFIER = 'code_verifier does not match the code_challenge.';

    private const int MAX_CODE_VERIFIER_LENGTH = 128;

    public function __construct(
        private OAuthClients $clients,
        private OAuthAuthorizationCodes $codes,
        private OAuthRefreshTokens $refreshTokens,
        private ApiTokenHashing $hashing,
        private IssueOAuthTokenPair $issueOAuthTokenPair,
        private TenantContext $tenantContext,
        private Transactions $transactions,
    ) {
    }

    /**
     * Call it with no transaction open and no tenant other than none; afterwards the tenant is the code's organization.
     * The entity manager is cleared.
     *
     * @param array<array-key, mixed> $parameters the request's form fields: code, redirect_uri, client_id, code_verifier
     *
     * @throws OAuthTokenRefused   invalid_request for a missing or malformed field, invalid_client for an unknown
     *                             client_id, invalid_grant for a code that is unknown, used, expired, of a person who
     *                             left, of another client or redirect URI, or whose challenge the verifier does not
     *                             meet, or when the person has too many live tokens; no token is issued
     * @throws TenantSwitchRefused when a transaction is open
     */
    public function __invoke(array $parameters, \DateTimeImmutable $now): IssuedOAuthTokens
    {
        $plainCode = OAuthTokenParameters::required($parameters, 'code', 64);
        $redirectUri = OAuthTokenParameters::required($parameters, 'redirect_uri', OAuthRedirectUri::MAX_LENGTH);
        $client = $this->clientOf(OAuthTokenParameters::required($parameters, 'client_id', AuthorizeOAuthClient::MAX_CLIENT_ID_LENGTH));
        $codeVerifier = OAuthTokenParameters::required($parameters, 'code_verifier', self::MAX_CODE_VERIFIER_LENGTH);
        if (1 !== preg_match(OAuthAuthorizationCode::CODE_VERIFIER_SHAPE, $codeVerifier)) {
            throw OAuthTokenRefused::invalidRequest('code_verifier is 43 to 128 characters of A-Z, a-z, 0-9, ".", "_", "~" and "-".');
        }
        if (1 !== preg_match(OAuthAuthorizationCode::PLAIN_CODE_SHAPE, $plainCode)) {
            throw OAuthTokenRefused::invalidGrant(self::UNUSABLE_CODE);
        }

        $codeHash = $this->hashing->hash($plainCode);
        $organizationId = $this->codes->organizationOfLiveCode($codeHash) ?? throw OAuthTokenRefused::invalidGrant(self::UNUSABLE_CODE);
        $code = $this->codes->consume($organizationId, $codeHash, $now) ?? throw OAuthTokenRefused::invalidGrant(self::UNUSABLE_CODE);
        if (!hash_equals($code->clientId(), $client->clientId())) {
            throw OAuthTokenRefused::invalidGrant(self::OTHER_CLIENT);
        }
        // Exactly as at authorize (RFC 6749 section 4.1.3): the loopback port too, which matchesRegistered() ignores.
        if ($code->redirectUri() !== $redirectUri) {
            throw OAuthTokenRefused::invalidGrant(self::OTHER_REDIRECT_URI);
        }
        if (!$code->isVerifiedBy($codeVerifier)) {
            throw OAuthTokenRefused::invalidGrant(self::WRONG_VERIFIER);
        }

        $this->tenantContext->set($organizationId);

        return $this->transactions->run(fn (): IssuedOAuthTokens => ($this->issueOAuthTokenPair)(
            $organizationId,
            $code->userId(),
            $client,
            $code->scopes(),
            $this->refreshTokens->nextIdentity(),
            $now,
        ));
    }

    /**
     * @throws OAuthTokenRefused invalid_client
     */
    private function clientOf(string $clientId): OAuthClient
    {
        return $this->clients->byClientId($clientId) ?? throw new OAuthTokenRefused(OAuthTokenRefused::INVALID_CLIENT, OAuthTokenRefused::UNKNOWN_CLIENT);
    }
}

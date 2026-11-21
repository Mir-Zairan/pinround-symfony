<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\OAuthAuthorizationCode;
use App\Identity\Domain\OAuthAuthorizationCodes;
use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthClients;
use App\Identity\Domain\OAuthRedirectUri;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Application\Transactions;

/**
 * The authorization_code grant of the token endpoint (RFC 6749 section 4.1.3, RFC 7636, ADR-0050): a public client
 * trades a code for an access token and a refresh token. One transaction under the code's organization uses the code
 * up, checks that it was issued to this client_id, for exactly this redirect_uri and with the S256 challenge of this
 * code_verifier, and issues the tokens in the family named by the code's id. A code that fails a check stays used, and
 * no token is issued. A used code that comes back revokes the tokens issued with it (RFC 6749 section 4.1.2); as the
 * second of two trades at once waits on the first's row lock until it commits, it finds them.
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
        private ApiTokenHashing $hashing,
        private IssueOAuthTokenPair $issueOAuthTokenPair,
        private RevokeOAuthToken $revokeOAuthToken,
        private TenantContext $tenantContext,
        private Transactions $transactions,
    ) {
    }

    /**
     * Call it with no transaction open and no tenant other than none; afterwards the tenant may be the code's
     * organization.
     *
     * @param array<array-key, mixed> $parameters the request's form fields: code, redirect_uri, client_id, code_verifier
     *
     * @throws OAuthTokenRefused   invalid_request for a missing or malformed field, invalid_client for an unknown
     *                             client_id, invalid_grant for a code that is unknown, used, expired, of a person who
     *                             left, of another client or redirect URI, or whose challenge the verifier does not
     *                             meet, or when the person has too many live tokens; no token is issued, and a used
     *                             code has revoked the tokens issued with it
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
        $organizationId = $this->codes->organizationOfCode($codeHash) ?? throw OAuthTokenRefused::invalidGrant(self::UNUSABLE_CODE);
        $this->tenantContext->set($organizationId);
        // A refusal is returned, not thrown, so the transaction commits the code's use and any revocation.
        $outcome = $this->transactions->run(fn (): IssuedOAuthTokens|OAuthTokenRefused => $this->traded($codeHash, $client, $redirectUri, $codeVerifier, $now));
        if ($outcome instanceof OAuthTokenRefused) {
            throw $outcome;
        }

        return $outcome;
    }

    private function traded(string $codeHash, OAuthClient $client, string $redirectUri, string $codeVerifier, \DateTimeImmutable $now): IssuedOAuthTokens|OAuthTokenRefused
    {
        $code = $this->codes->consume($codeHash, $now);
        if (null === $code) {
            $usedCodeId = $this->codes->idOfUsedCode($codeHash);
            if (null !== $usedCodeId) {
                $this->revokeOAuthToken->revokeFamilyBySystem($usedCodeId, $now);
            }

            return OAuthTokenRefused::invalidGrant(self::UNUSABLE_CODE);
        }
        if (!hash_equals($code->clientId(), $client->clientId())) {
            return OAuthTokenRefused::invalidGrant(self::OTHER_CLIENT);
        }
        // Exactly as at authorize (RFC 6749 section 4.1.3): the loopback port too, which matchesRegistered() ignores.
        if ($code->redirectUri() !== $redirectUri) {
            return OAuthTokenRefused::invalidGrant(self::OTHER_REDIRECT_URI);
        }
        if (!$code->isVerifiedBy($codeVerifier)) {
            return OAuthTokenRefused::invalidGrant(self::WRONG_VERIFIER);
        }

        try {
            return ($this->issueOAuthTokenPair)($code->organizationId(), $code->userId(), $client, $code->scopes(), $code->id(), $now);
        } catch (OAuthTokenRefused $refusal) {
            return $refusal;
        }
    }

    /**
     * @throws OAuthTokenRefused invalid_client
     */
    private function clientOf(string $clientId): OAuthClient
    {
        return $this->clients->byClientId($clientId) ?? throw new OAuthTokenRefused(OAuthTokenRefused::INVALID_CLIENT, OAuthTokenRefused::UNKNOWN_CLIENT);
    }
}

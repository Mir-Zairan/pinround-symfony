<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\IssuedApiToken;
use App\Identity\Domain\OAuthClient;
use App\Identity\Domain\OAuthGrantType;
use App\Identity\Domain\OAuthRefreshToken;
use App\Identity\Domain\OAuthRefreshTokens;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The tokens of one step of an OAuth grant (ADR-0050): an access token, which is an idn_api_token row of
 * ApiToken::OAUTH_ACCESS_LIFETIME_SECONDS issued through IssueApiToken (so the one token check of /mcp and /api/v1, the
 * cap and the audit apply), and a refresh token of the grant's family that names it. The refresh token is always
 * stored, so a revocation can tell which client an access token went to, and handed out only to a client that
 * registered the refresh_token grant.
 */
final readonly class IssueOAuthTokenPair
{
    /** The access token's name on the tokens page when the client gave no name. */
    public const string UNNAMED_CLIENT = 'Connected app';

    /**
     * The cap counts the access tokens of connected apps, which the tokens page does not list, beside the personal
     * tokens it does (ApiToken::isPersonal), so the refusal names both ways to free one.
     */
    public const string TOO_MANY_TOKENS = 'You have %d tokens that still work in this organization, counting the ones your connected apps hold. Revoke a personal token on the tokens page or sign out of a connected app, then connect again.';

    public function __construct(private IssueApiToken $issueApiToken, private OAuthRefreshTokens $refreshTokens, private ApiTokenHashing $hashing)
    {
    }

    /**
     * Writes in the caller's transaction, which must be open under $organizationId as tenant.
     *
     * @param list<ApiTokenScope> $scopes   the grant's
     * @param string              $familyId the grant's family: the code's id at the code exchange, so a replayed code
     *                                      finds the tokens it gave
     *
     * @throws OAuthTokenRefused invalid_grant when the person has IssueApiToken::MAX_LIVE_TOKENS_PER_USER live tokens
     *                           in the organization; nothing is added
     * @throws \LogicException   when no transaction is open, or $organizationId is not the tenant
     */
    public function __invoke(OrganizationId $organizationId, UserId $userId, OAuthClient $client, array $scopes, string $familyId, \DateTimeImmutable $now): IssuedOAuthTokens
    {
        try {
            $accessToken = $this->issueApiToken->issueForOAuthClient($organizationId, $userId, $client->clientName() ?? self::UNNAMED_CLIENT, $scopes, $now);
        } catch (TooManyApiTokens $refusal) {
            throw self::tooManyTokens($refusal);
        }

        return $this->pairedWith($accessToken, $client, $familyId, null, $now);
    }

    /**
     * The pair a refresh issues for $traded, in its family and with its grant's scopes, as __invoke() but with no
     * ApiTokenIssued (IssueApiToken::renewForOAuthClient()). Writes in the caller's transaction, under $traded's
     * organization as tenant.
     *
     * @throws OAuthTokenRefused as __invoke()
     * @throws \LogicException   as __invoke()
     */
    public function renewal(OAuthRefreshToken $traded, OAuthClient $client, \DateTimeImmutable $now): IssuedOAuthTokens
    {
        try {
            $accessToken = $this->issueApiToken->renewForOAuthClient($traded->organizationId(), $traded->userId(), $client->clientName() ?? self::UNNAMED_CLIENT, $traded->scopes(), $now);
        } catch (TooManyApiTokens $refusal) {
            throw self::tooManyTokens($refusal);
        }

        return $this->pairedWith($accessToken, $client, $traded->familyId(), $traded->familyCreatedAt(), $now);
    }

    private static function tooManyTokens(TooManyApiTokens $refusal): OAuthTokenRefused
    {
        return new OAuthTokenRefused(OAuthTokenRefused::INVALID_GRANT, \sprintf(self::TOO_MANY_TOKENS, IssueApiToken::MAX_LIVE_TOKENS_PER_USER), $refusal);
    }

    /**
     * Adds the refresh token that names $accessToken.
     *
     * @param ?\DateTimeImmutable $familyCreatedAt null for a new family, which starts $now
     */
    private function pairedWith(IssuedApiToken $accessToken, OAuthClient $client, string $familyId, ?\DateTimeImmutable $familyCreatedAt, \DateTimeImmutable $now): IssuedOAuthTokens
    {
        $token = $accessToken->token();
        $refreshToken = OAuthRefreshToken::issue(
            $this->refreshTokens->nextIdentity(),
            $familyId,
            $token->organizationId(),
            $token->userId(),
            $client->clientId(),
            $token->scopes(),
            $token->id(),
            $this->hashing,
            $now,
            $familyCreatedAt,
        );
        $this->refreshTokens->add($refreshToken->token());

        return new IssuedOAuthTokens(
            $accessToken->reveal(),
            \in_array(OAuthGrantType::RefreshToken, $client->grantTypes(), true) ? $refreshToken->reveal() : null,
            ApiToken::OAUTH_ACCESS_LIFETIME_SECONDS,
            array_map(static fn (ApiTokenScope $scope): string => $scope->value, $token->scopes()),
        );
    }
}

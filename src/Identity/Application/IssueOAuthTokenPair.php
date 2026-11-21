<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokenScope;
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

    public function __construct(private IssueApiToken $issueApiToken, private OAuthRefreshTokens $refreshTokens, private ApiTokenHashing $hashing)
    {
    }

    /**
     * Writes in the caller's transaction, which must be open under $organizationId as tenant.
     *
     * @param list<ApiTokenScope> $scopes   the grant's
     * @param string              $familyId a new id from OAuthRefreshTokens::nextIdentity() at the code exchange, the
     *                                      traded token's at a refresh
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
            throw new OAuthTokenRefused(OAuthTokenRefused::INVALID_GRANT, \sprintf('You have %d tokens that still work in this organization. Revoke one on the tokens page, then connect again.', IssueApiToken::MAX_LIVE_TOKENS_PER_USER), $refusal);
        }
        $refreshToken = OAuthRefreshToken::issue(
            $this->refreshTokens->nextIdentity(),
            $familyId,
            $organizationId,
            $userId,
            $client->clientId(),
            $scopes,
            $accessToken->token()->id(),
            $this->hashing,
            $now,
        );
        $this->refreshTokens->add($refreshToken->token());

        return new IssuedOAuthTokens(
            $accessToken->reveal(),
            \in_array(OAuthGrantType::RefreshToken, $client->grantTypes(), true) ? $refreshToken->reveal() : null,
            ApiToken::OAUTH_ACCESS_LIFETIME_SECONDS,
            array_map(static fn (ApiTokenScope $scope): string => $scope->value, $accessToken->token()->scopes()),
        );
    }
}

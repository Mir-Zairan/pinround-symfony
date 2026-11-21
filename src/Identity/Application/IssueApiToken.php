<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Api\Event\ApiTokenIssued;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokens;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\InvalidApiToken;
use App\Identity\Domain\IssuedApiToken;
use App\Identity\Domain\UnknownApiTokenScope;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * A person makes a personal access token for Claude Code or the browser plugin (P09.T1, ADR-0021), at most
 * MAX_LIVE_TOKENS_PER_USER that still work per person and organization. Its write and ApiTokenIssued, for the audit
 * log, share the caller's transaction (RequestTransaction on the web), which must be open under $organizationId as
 * tenant: the cap's lock lasts until it ends.
 */
final readonly class IssueApiToken
{
    public const int MAX_LIVE_TOKENS_PER_USER = 20;
    public const string UNKNOWN_SCOPE = 'Choose only the things listed on this page.';

    public function __construct(private ApiTokens $tokens, private ApiTokenHashing $hashing, private IdentityEvents $events)
    {
    }

    /**
     * @param list<ApiTokenScope> $scopes
     *
     * @return IssuedApiToken the token, saved, and its plain text, to show once
     *
     * @throws InvalidApiToken  when ApiToken::issue() refuses the name, scopes or lifetime; nothing is added
     * @throws TooManyApiTokens when the user has MAX_LIVE_TOKENS_PER_USER tokens in the organization that are neither
     *                          revoked nor expired at $now; nothing is added
     * @throws \LogicException  when no transaction is open, or $organizationId is not the tenant
     */
    public function __invoke(OrganizationId $organizationId, UserId $userId, string $name, array $scopes, int $lifetimeDays, \DateTimeImmutable $now): IssuedApiToken
    {
        return $this->saved(ApiToken::issue($this->tokens->nextIdentity(), $organizationId, $userId, $name, $scopes, $this->hashing, $now, $lifetimeDays), $now);
    }

    /**
     * The access token of an OAuth grant (ADR-0050), named after the client, lasting
     * ApiToken::OAUTH_ACCESS_LIFETIME_SECONDS; it counts towards MAX_LIVE_TOKENS_PER_USER as any other token.
     *
     * @param string              $clientName the client's name, or a stand-in for a client without one
     * @param list<ApiTokenScope> $scopes     the grant's
     *
     * @return IssuedApiToken the token, saved, and its plain text, to hand to the client once
     *
     * @throws InvalidApiToken  when ApiToken::issueForOAuthClient() refuses the name or scopes; nothing is added
     * @throws TooManyApiTokens as __invoke(); nothing is added
     * @throws \LogicException  as __invoke()
     */
    public function issueForOAuthClient(OrganizationId $organizationId, UserId $userId, string $clientName, array $scopes, \DateTimeImmutable $now): IssuedApiToken
    {
        return $this->saved(ApiToken::issueForOAuthClient($this->tokens->nextIdentity(), $organizationId, $userId, $clientName, $scopes, $this->hashing, $now), $now);
    }

    /**
     * The access token a refresh issues in place of the one it revokes (ADR-0050), as issueForOAuthClient() but with
     * no ApiTokenIssued: nobody acted, and the event would tell the audit log the person made a token every hour.
     *
     * @param list<ApiTokenScope> $scopes the grant's
     *
     * @throws InvalidApiToken  as issueForOAuthClient(); nothing is added
     * @throws TooManyApiTokens as __invoke(); nothing is added
     * @throws \LogicException  as __invoke()
     */
    public function renewForOAuthClient(OrganizationId $organizationId, UserId $userId, string $clientName, array $scopes, \DateTimeImmutable $now): IssuedApiToken
    {
        $issued = ApiToken::issueForOAuthClient($this->tokens->nextIdentity(), $organizationId, $userId, $clientName, $scopes, $this->hashing, $now);
        $this->addUnderCap($issued->token(), $now);

        return $issued;
    }

    /**
     * Adds the token under the cap and publishes ApiTokenIssued.
     */
    private function saved(IssuedApiToken $issued, \DateTimeImmutable $now): IssuedApiToken
    {
        $token = $issued->token();
        $organizationId = $token->organizationId();
        $userId = $token->userId();
        $this->addUnderCap($token, $now);
        $this->events->publish(new ApiTokenIssued(
            $organizationId->toString(),
            $token->id(),
            $userId->toString(),
            array_map(static fn (ApiTokenScope $scope): string => $scope->value, $token->scopes()),
            $token->expiresAt(),
            $userId->toString(),
            $now,
        ));

        return $issued;
    }

    /**
     * @throws TooManyApiTokens when the owner has MAX_LIVE_TOKENS_PER_USER live tokens; nothing is added
     */
    private function addUnderCap(ApiToken $token, \DateTimeImmutable $now): void
    {
        $this->tokens->lockIssuingFor($token->organizationId(), $token->userId());
        if ($this->tokens->countLiveOf($token->userId(), $now) >= self::MAX_LIVE_TOKENS_PER_USER) {
            throw new TooManyApiTokens(self::MAX_LIVE_TOKENS_PER_USER);
        }
        $this->tokens->add($token);
    }

    /**
     * The tokens page's way in: the scopes by name, as its checkboxes send them, and a result without Domain types.
     *
     * @param list<string> $scopeNames ApiTokenScope values; repeats count once
     *
     * @return NewApiToken the token, saved, and its plain text, to show once
     *
     * @throws ApiTokenRefused  when the name, a scope name or the lifetime is refused; nothing is added
     * @throws TooManyApiTokens as __invoke(); nothing is added
     * @throws \LogicException  as __invoke()
     */
    public function issueWithScopeNames(OrganizationId $organizationId, UserId $userId, string $name, array $scopeNames, int $lifetimeDays, \DateTimeImmutable $now): NewApiToken
    {
        // More names than there are scopes can only be a crafted form; refused before any of them is read.
        if (\count($scopeNames) > \count(ApiTokenScope::cases())) {
            throw new ApiTokenRefused(self::UNKNOWN_SCOPE);
        }
        try {
            $issued = $this($organizationId, $userId, $name, ApiTokenScope::fromValues($scopeNames), $lifetimeDays, $now);
        } catch (UnknownApiTokenScope) {
            throw new ApiTokenRefused(self::UNKNOWN_SCOPE);
        } catch (InvalidApiToken $refusal) {
            throw new ApiTokenRefused($refusal->getMessage(), previous: $refusal);
        }

        return new NewApiToken(ApiTokenView::of($issued->token()), $issued->reveal());
    }
}

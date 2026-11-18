<?php

declare(strict_types=1);

namespace App\Identity\Application;

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
 * MAX_LIVE_TOKENS_PER_USER that still work per person and organization. Its write shares the caller's transaction
 * (RequestTransaction on the web), which must be open under $organizationId as tenant: the cap's lock lasts until it ends.
 */
final readonly class IssueApiToken
{
    public const int MAX_LIVE_TOKENS_PER_USER = 20;
    public const string UNKNOWN_SCOPE = 'Choose only the things listed on this page.';

    public function __construct(private ApiTokens $tokens, private ApiTokenHashing $hashing)
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
        $issued = ApiToken::issue($this->tokens->nextIdentity(), $organizationId, $userId, $name, $scopes, $this->hashing, $now, $lifetimeDays);

        $this->tokens->lockIssuingFor($organizationId, $userId);
        if ($this->tokens->countLiveOf($userId, $now) >= self::MAX_LIVE_TOKENS_PER_USER) {
            throw new TooManyApiTokens(self::MAX_LIVE_TOKENS_PER_USER);
        }
        $this->tokens->add($issued->token());

        return $issued;
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

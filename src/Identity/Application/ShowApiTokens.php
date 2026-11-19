<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokens;
use App\Identity\Domain\ApiTokenScope;
use App\SharedKernel\Domain\UserId;

/**
 * What the tokens page shows: a person's own tokens that still work, and the choices its form offers.
 */
final readonly class ShowApiTokens
{
    public const int DEFAULT_LIFETIME_DAYS = ApiToken::DEFAULT_LIFETIME_DAYS;

    public function __construct(private ApiTokens $tokens)
    {
    }

    /**
     * The user's tokens in the tenant that are neither revoked nor expired at $now, newest first.
     *
     * @return list<ApiTokenView>
     *
     * @throws \LogicException when no tenant is set
     */
    public function liveOf(UserId $userId, \DateTimeImmutable $now): array
    {
        return array_map(ApiTokenView::of(...), $this->tokens->liveForUser($userId, $now));
    }

    /**
     * @return list<string> every scope a token may have, in display order
     */
    public function scopeNames(): array
    {
        return array_map(static fn (ApiTokenScope $scope): string => $scope->value, ApiTokenScope::cases());
    }

    /**
     * @return list<string> the scopes the form starts with ticked: Claude Code's defaults
     */
    public function defaultScopeNames(): array
    {
        return array_map(static fn (ApiTokenScope $scope): string => $scope->value, ApiTokenScope::claudeCodeDefaults());
    }
}

<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiTokens;
use App\SharedKernel\Domain\UserId;

/**
 * A person revokes one of their own personal access tokens (P09.T1, ADR-0021); it stops working at once. Only the
 * owner's tokens in the tenant are found: the owner never changes, so the check before the conditional revoke holds.
 * Its write shares the caller's transaction (RequestTransaction on the web).
 */
final readonly class RevokeApiToken
{
    public function __construct(private ApiTokens $tokens)
    {
    }

    /**
     * @return bool true when this call revoked it; false when it was revoked already, as after a double click
     *
     * @throws ApiTokenNotFound when $tokenId is not a UUID, or no token of the tenant with it is $ownerId's; nothing
     *                          is changed
     * @throws \LogicException  when no tenant is set
     */
    public function __invoke(UserId $ownerId, string $tokenId, \DateTimeImmutable $now): bool
    {
        $token = $this->tokens->byIdForOwner($tokenId, $ownerId) ?? throw new ApiTokenNotFound();

        return $this->tokens->revoke($token->id(), $now);
    }
}

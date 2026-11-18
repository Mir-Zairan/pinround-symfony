<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Api\Event\ApiTokenRevoked;
use App\Identity\Domain\ApiTokens;
use App\SharedKernel\Domain\UserId;

/**
 * A person revokes one of their own personal access tokens (P09.T1, ADR-0021); it stops working at once. Only the
 * owner's tokens in the tenant are found: the owner never changes, so the check before the conditional revoke holds.
 * Its write and ApiTokenRevoked, for the audit log, share the caller's transaction (RequestTransaction on the web).
 */
final readonly class RevokeApiToken
{
    public function __construct(private ApiTokens $tokens, private IdentityEvents $events)
    {
    }

    /**
     * @return bool true when this call revoked it; false when it was revoked already, as after a double click
     *
     * @throws ApiTokenNotFound when $tokenId is not a UUID, or no token of the tenant with it is $ownerId's; nothing
     *                          is changed
     * @throws \LogicException  when no tenant is set, or no transaction is open
     */
    public function __invoke(UserId $ownerId, string $tokenId, \DateTimeImmutable $now): bool
    {
        $token = $this->tokens->byIdForOwner($tokenId, $ownerId) ?? throw new ApiTokenNotFound();

        if (!$this->tokens->revoke($token->id(), $now)) {
            return false;
        }
        $this->events->publish(new ApiTokenRevoked($token->organizationId()->toString(), $token->id(), $ownerId->toString(), $ownerId->toString(), $now));

        return true;
    }
}

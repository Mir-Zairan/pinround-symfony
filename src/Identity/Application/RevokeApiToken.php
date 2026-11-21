<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Api\ApiTokenNotInOrganization;
use App\Identity\Api\Event\ApiTokenRevoked;
use App\Identity\Domain\ApiTokens;
use App\SharedKernel\Domain\UserId;

/**
 * A person revokes one of their own personal access tokens, or an Admin any token of the organization (P09.T1,
 * ADR-0021); it stops working at once. Only tokens of the tenant are found, and for a person only their own: the owner
 * never changes, so the check before the conditional revoke holds. The token endpoint stops OAuth access tokens with
 * bySystem().
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

    /**
     * The token endpoint stops an OAuth access token that nobody revoked: one a refresh replaced, or one of a family
     * revoked when a used refresh token or code came back (ADR-0050). ApiTokenRevoked names a person as actor, so none
     * is published; the audit log would otherwise say the owner revoked it.
     *
     * @return bool true when this call revoked it; false when $tokenId is not a UUID, no token of the tenant has it, as
     *              after the retention job purged it, or it was revoked already
     *
     * @throws \LogicException when no tenant is set
     */
    public function bySystem(string $tokenId, \DateTimeImmutable $now): bool
    {
        return $this->tokens->revoke($tokenId, $now);
    }

    /**
     * An Admin revokes any person's token of the tenant. It does not check that $adminId is an Admin: the caller's voter
     * has. ApiTokenRevoked names the owner as the user and the Admin as the actor.
     *
     * @return bool true when this call revoked it; false when it was revoked already, and then nothing is published
     *
     * @throws ApiTokenNotInOrganization when $tokenId is not a UUID or no token of the tenant has it; nothing is changed
     * @throws \LogicException           when no tenant is set, or no transaction is open
     */
    public function asAdmin(UserId $adminId, string $tokenId, \DateTimeImmutable $now): bool
    {
        $token = $this->tokens->byId($tokenId) ?? throw new ApiTokenNotInOrganization();

        if (!$this->tokens->revoke($token->id(), $now)) {
            return false;
        }
        $this->events->publish(new ApiTokenRevoked($token->organizationId()->toString(), $token->id(), $token->userId()->toString(), $adminId->toString(), $now));

        return true;
    }
}

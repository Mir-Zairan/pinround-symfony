<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Api\CurrentUser;
use App\Identity\Api\OAuthConnectionNotFound;
use App\Identity\Api\OAuthConnectionRefused;
use App\Identity\Domain\OAuthRefreshTokens;
use App\SharedKernel\Application\TenantContext;

/**
 * A person ends one of their OAuth connections, or an Admin any of the organization (ADR-0050): its refresh token and
 * the access token that may still work with it stop at once, through RevokeOAuthToken::endFamily(). OAuthConnectionVoter
 * decides who may, on the family's owner as read under the tenant. Its writes and ApiTokenRevoked, for the audit log,
 * share the caller's transaction (RequestTransaction on the web).
 */
final readonly class EndOAuthConnection
{
    public function __construct(
        private OAuthRefreshTokens $refreshTokens,
        private OAuthConnectionAccess $access,
        private CurrentUser $currentUser,
        private RevokeOAuthToken $revokeOAuthToken,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * @return bool true when this call ended it; false when it was ended already, as after a double click or a second
     *              end at the same time
     *
     * @throws OAuthConnectionNotFound when $familyId is not a UUID or no family of the tenant has it; nothing is changed
     * @throws OAuthConnectionRefused  when the voter refuses the request's person; nothing is changed
     * @throws \LogicException         when no tenant is set, or no transaction is open
     */
    public function __invoke(string $familyId, \DateTimeImmutable $now): bool
    {
        $organizationId = $this->tenantContext->require();
        $ownerId = $this->refreshTokens->ownerOfFamily($familyId) ?? throw new OAuthConnectionNotFound();
        $connection = new OAuthConnectionOwner(strtolower($familyId), $organizationId, $ownerId);
        $actorId = $this->currentUser->id();
        if (null === $actorId || !$this->access->isGranted(OAuthConnectionPermission::END, $connection)) {
            throw new OAuthConnectionRefused();
        }

        return $this->revokeOAuthToken->endFamily($connection->familyId, $ownerId, $actorId, $now);
    }
}

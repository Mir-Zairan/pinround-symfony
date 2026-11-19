<?php

declare(strict_types=1);

namespace App\Identity\Api\Event;

/**
 * A personal access token was revoked and stopped working (P09.T1, ADR-0021). Published only by the revoke that
 * changed it, so a second click publishes nothing.
 */
final readonly class ApiTokenRevoked implements IdentityEvent
{
    /**
     * @param string $organizationId a canonical UUID, the organization the token worked in
     * @param string $tokenId        a canonical UUID
     * @param string $userId         a canonical UUID, the token's owner
     * @param string $actorId        a canonical UUID, the person who revoked it: the owner, or an admin
     */
    public function __construct(
        public string $organizationId,
        public string $tokenId,
        public string $userId,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}

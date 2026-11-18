<?php

declare(strict_types=1);

namespace App\Identity\Api\Event;

/**
 * A person made a personal access token (P09.T1, ADR-0021). It carries ids, scope names and times only: never the
 * token, its hash, its prefix or its name, which is the person's own text.
 */
final readonly class ApiTokenIssued implements IdentityEvent
{
    /**
     * @param string       $organizationId a canonical UUID, the organization the token works in
     * @param string       $tokenId        a canonical UUID
     * @param string       $userId         a canonical UUID, the token's owner
     * @param list<string> $scopes         ApiTokenScope values, in the order the token keeps them
     * @param string       $actorId        a canonical UUID, the person who made it: always the owner
     */
    public function __construct(
        public string $organizationId,
        public string $tokenId,
        public string $userId,
        public array $scopes,
        public \DateTimeImmutable $expiresAt,
        public string $actorId,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}

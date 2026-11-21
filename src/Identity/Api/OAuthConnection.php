<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\SharedKernel\Domain\UserId;

/**
 * One live OAuth connection of a person in the organization (ADR-0050): a refresh token family, from the consent page
 * on, whose newest refresh token still works. Never a token or its hash.
 */
final readonly class OAuthConnection
{
    /**
     * @param string                  $familyId      a canonical UUID, the id a connection is ended by
     * @param string                  $clientName    the name the client registered with, or its client_id when it gave
     *                                               none; untrusted text the client chose
     * @param \DateTimeImmutable      $connectedAt   when the person allowed it: the family's first token; whole seconds
     * @param \DateTimeImmutable|null $lastRenewedAt when the client last traded its refresh token; null when it never
     *                                               has; whole seconds
     * @param list<string>            $scopes        ApiTokenScope values, in its cases' order
     */
    public function __construct(
        public string $familyId,
        public UserId $userId,
        public string $clientName,
        public \DateTimeImmutable $connectedAt,
        public ?\DateTimeImmutable $lastRenewedAt,
        public array $scopes,
    ) {
    }
}

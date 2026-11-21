<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Api\OAuthConnection;
use App\SharedKernel\Domain\UserId;

/**
 * What the connected apps page shows: a person's own live OAuth connections in the tenant (ADR-0050).
 */
final readonly class ShowOAuthConnections
{
    public function __construct(private OAuthConnections $connections)
    {
    }

    /**
     * @return list<OAuthConnection> the newest connected first, at most OAuthConnections::MAX_LISTED
     *
     * @throws \LogicException when no tenant is set
     */
    public function liveOf(UserId $userId, \DateTimeImmutable $now): array
    {
        return $this->connections->liveOf($userId, $now);
    }
}

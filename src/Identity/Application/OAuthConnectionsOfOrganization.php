<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Api\OrganizationOAuthConnections;

final readonly class OAuthConnectionsOfOrganization implements OrganizationOAuthConnections
{
    public function __construct(private OAuthConnections $connections, private EndOAuthConnection $endOAuthConnection)
    {
    }

    public function livePage(\DateTimeImmutable $now, int $limit, ?\DateTimeImmutable $beforeConnectedAt = null, ?string $beforeFamilyId = null): array
    {
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException(\sprintf('A page of connections holds 1 to %d.', self::MAX_PAGE_SIZE));
        }

        return $this->connections->livePage($now, $limit, $beforeConnectedAt, $beforeFamilyId);
    }

    public function end(string $familyId, \DateTimeImmutable $now): bool
    {
        return ($this->endOAuthConnection)($familyId, $now);
    }
}

<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\SharedKernel\Domain\OrganizationId;

/**
 * What the personal access token a request authenticated with allows (ADR-0021): the organization it works in and the
 * scopes it carries on top of what its user may do. Never the token itself.
 */
final readonly class ApiTokenAccess
{
    /**
     * @param string       $tokenId the id of the token's idn_api_token row, for the audit
     * @param list<string> $scopes  the scope names as stored, such as "read" and "rounds:write"
     */
    public function __construct(public string $tokenId, public OrganizationId $organizationId, public array $scopes)
    {
    }

    public function hasScope(string $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }
}

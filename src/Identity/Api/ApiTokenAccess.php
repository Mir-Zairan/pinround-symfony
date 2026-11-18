<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\SharedKernel\Domain\OrganizationId;

/**
 * What the personal access token a request authenticated with allows (ADR-0021): the organization it works in, the
 * scopes it carries on top of what its user may do, and when it stops working. Never the token itself.
 */
final readonly class ApiTokenAccess
{
    /** The instant the token stops working, in UTC whatever zone it was given in. */
    public \DateTimeImmutable $expiresAt;

    /**
     * @param string             $tokenId   the id of the token's idn_api_token row, for the audit
     * @param list<string>       $scopes    the scope names as stored, such as "read" and "rounds:write"
     * @param \DateTimeImmutable $expiresAt the token works until just before this instant
     */
    public function __construct(public string $tokenId, public OrganizationId $organizationId, public array $scopes, \DateTimeImmutable $expiresAt)
    {
        $this->expiresAt = $expiresAt->setTimezone(new \DateTimeZone('UTC'));
    }

    public function hasScope(string $scope): bool
    {
        return \in_array($scope, $this->scopes, true);
    }
}

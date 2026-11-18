<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * A live token as resolve_api_token gives it before the tenant is known: ids only. The caller sets the tenant from
 * it and reads the token's scopes under the normal policy.
 */
final readonly class ApiTokenOwner
{
    public function __construct(public string $tokenId, public OrganizationId $organizationId, public UserId $userId)
    {
    }
}

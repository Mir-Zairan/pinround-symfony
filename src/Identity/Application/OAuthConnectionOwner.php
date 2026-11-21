<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Whose OAuth connection a family is, and in which organization: what OAuthConnectionVoter decides on.
 */
final readonly class OAuthConnectionOwner
{
    public function __construct(public string $familyId, public OrganizationId $organizationId, public UserId $userId)
    {
    }
}

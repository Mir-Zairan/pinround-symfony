<?php

declare(strict_types=1);

namespace App\Assist\Application\LocalAi;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Whose a laptop package is, as resolve_laptop_package gives it before the tenant is checked: ids only.
 */
final readonly class LocalPackageOwner
{
    public function __construct(public OrganizationId $organizationId, public UserId $userId)
    {
    }
}

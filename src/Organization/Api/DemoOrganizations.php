<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Dev and test demo data only: the organizations the demo seed made, for the demo seed steps of other modules.
 */
interface DemoOrganizations
{
    /**
     * Finds a demo organization the demo seed made, by its slug. Each look runs in its own transaction under the
     * organization as tenant and clears the EntityManager before and after it, so unsaved changes are lost; the tenant
     * is put back as it was.
     *
     * @return OrganizationId|null null when the slug is not a demo organization's, or the seed has not made it yet
     *
     * @throws TenantSwitchRefused when a transaction is open, as it would keep its own tenant for the look
     *
     * @phpstan-impure
     */
    public function idBySlug(string $slug): ?OrganizationId;
}

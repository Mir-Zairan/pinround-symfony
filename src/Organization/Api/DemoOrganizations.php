<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;

/**
 * Dev and test demo data only: the organizations the demo seed made, for the demo seed steps of other modules.
 */
interface DemoOrganizations
{
    /**
     * Finds a demo organization the demo seed made, by its slug. Each look runs in its own transaction under the
     * organization as tenant and clears the EntityManager after it, so call it outside any transaction: unsaved
     * changes are lost.
     *
     * @return OrganizationId|null null when the slug is not a demo organization's, or the seed has not made it yet
     *
     * @phpstan-impure
     */
    public function idBySlug(string $slug): ?OrganizationId;
}

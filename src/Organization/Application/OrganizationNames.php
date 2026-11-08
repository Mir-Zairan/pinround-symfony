<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;

/**
 * Organization names under row-level security: a name is readable only while its organization is the tenant.
 */
interface OrganizationNames
{
    /**
     * Reads each name in its own short transaction with that organization as the tenant, then puts the tenant back as
     * it was. Clears the entity manager first, so call it before any write and with no transaction open.
     *
     * @param list<OrganizationId> $organizationIds
     *
     * @return array<string, string> the names by organization id, in the order given; an organization without a row is
     *                               left out
     *
     * @throws TenantSwitchRefused when a transaction is open
     */
    public function namesOf(array $organizationIds): array;

    /**
     * @return string|null null when no tenant is set or no transaction with it is open, as on a page without the
     *                     request transaction
     */
    public function nameOfTenant(): ?string;
}

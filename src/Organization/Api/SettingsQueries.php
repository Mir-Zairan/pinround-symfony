<?php

declare(strict_types=1);

namespace App\Organization\Api;

use App\SharedKernel\Domain\OrganizationId;

/**
 * An organization's settings, for other modules. The reads run under row-level security, so they need an open
 * transaction whose tenant is the organization; under any other tenant, or none, they find no settings row.
 */
interface SettingsQueries
{
    /**
     * One query, or none: the autowired one keeps each answer until the next request or message, so it does not see a
     * change made since its first read in that request. A write that checks the policy in its own transaction is given
     * the uncached one (services.yaml). Extend, the default, when the organization has no settings row (or it is not visible under the
     * current tenant) or the row has no step policy.
     *
     * @throws \LogicException when the stored step policy is not one of StepPolicy's values
     */
    public function stepPolicyOf(OrganizationId $organizationId): StepPolicy;
}

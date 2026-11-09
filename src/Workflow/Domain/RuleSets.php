<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

use App\SharedKernel\Domain\OrganizationId;

/**
 * Rule sets of the organization of the current transaction. Both methods must run inside an open transaction under
 * that organization as tenant: without one no tenant is set, so a read sees no row and a write is refused.
 */
interface RuleSets
{
    /**
     * Adds the organization's rule set, or replaces the one it has; team rule sets stay as they are.
     *
     * @throws \LogicException when no transaction is open
     */
    public function saveForOrganization(OrganizationId $organizationId, RuleSet $ruleSet): void;

    /**
     * The organization's own rule set, never a team's; null when it has none.
     *
     * @throws \LogicException when no transaction is open
     */
    public function forOrganization(OrganizationId $organizationId): ?RuleSet;
}

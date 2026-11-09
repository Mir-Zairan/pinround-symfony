<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;

/**
 * Rule sets of the organization of the current transaction. Every method must run inside an open transaction under
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

    /**
     * Adds the team's own rule set, or replaces the one it has; the organization's and other teams' stay as they are.
     * Under another organization as tenant the database refuses the write, which fails the transaction.
     *
     * @throws \LogicException when no transaction is open
     */
    public function saveForTeam(OrganizationId $organizationId, TeamId $teamId, RuleSet $ruleSet): void;

    /**
     * The team's own rule set, never the organization's; null when the team has none (or it is another
     * organization's).
     *
     * @throws \LogicException when no transaction is open
     */
    public function forTeam(OrganizationId $organizationId, TeamId $teamId): ?RuleSet;
}

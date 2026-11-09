<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * The voter attributes for a team's own steps and rules (ADR-0013). WorkflowVoter decides them; the subject is the
 * team's TeamId.
 *
 * Company steps, the organization's rule set and the step policy itself are Admin only under every policy: ask
 * Organization\Api\OrganizationPermission::ADMIN on the OrganizationId for them. Workflow has no attribute of its own
 * for those.
 */
final class WorkflowPermission
{
    /** Add or remove the team's own steps: the team's Lead, under the step policy extend or team. */
    public const string STEP_CHANGE_TEAM = 'STEP_CHANGE_TEAM';

    /** Change the team's rules: the team's Lead, under the step policy team only. */
    public const string RULES_CHANGE_TEAM = 'RULES_CHANGE_TEAM';
}
